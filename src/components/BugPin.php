<?php

namespace portalium\feedback\components;

use portalium\feedback\bundles\BugPinAsset;
use portalium\feedback\Module;
use Yii;
use yii\base\Event;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use yii\web\UploadedFile;
use yii\web\View;

/**
 * Self-hosted receiver for the BugPin feedback widget (MIT, assets/bugpin). The widget is added to the pages of
 * signed-in users once feedback::key holds a key; reports are written under @runtime/feedback/<id>/ and mailed
 * to the addresses in feedback::mail.
 */
class BugPin
{
    public const KEY = 'feedback::key';
    public const MAIL = 'feedback::mail';
    public const MAX_FILES = 5;
    public const MAX_FILE_SIZE = 10485760;
    public const MAX_DATA_SIZE = 1048576;
    public const RATE_LIMIT = 10;
    public const RATE_WINDOW = 600;
    public const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    public static $basePath = '@runtime/feedback';

    public static function onBeginBody(Event $event): void
    {
        $settings = self::pageSettings();
        if ($settings === null) {
            return;
        }
        $view = $event->sender;
        $view->registerJs('window.BugPinSettings = ' . Json::htmlEncode($settings) . ';', View::POS_HEAD, 'bugpin-settings');
        BugPinAsset::register($view);
    }

    public static function pageSettings(): ?array
    {
        $app = Yii::$app;
        if (!$app instanceof \yii\web\Application || $app->request->isAjax || $app->user->isGuest) {
            return null;
        }
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $identity = $app->user->identity;
        $settings = [
            'apiKey' => $key,
            'serverUrl' => $app->request->hostInfo,
            'captureOnOpen' => true,
            'capturePixelRatio' => 1,
        ];
        $module = $app->getModule('feedback');
        if ($module instanceof Module) {
            $settings['position'] = $module->position;
            if ($module->launcherOffset) {
                $settings['launcherOffset'] = $module->launcherOffset;
            }
        }
        if (!empty($identity->username)) {
            $settings['reporterName'] = (string) $identity->username;
        }
        if (!empty($identity->email)) {
            $settings['reporterEmail'] = (string) $identity->email;
        }

        return $settings;
    }

    public static function key(): ?string
    {
        $key = trim((string) self::setting(self::KEY));

        return preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key) ? $key : null;
    }

    public static function accepts($given): bool
    {
        $key = self::key();

        return $key !== null && is_string($given) && hash_equals($key, $given);
    }

    /**
     * The widget copies every launcher/dialog field of this answer over its own defaults, a missing one included
     * (it becomes undefined and the launcher fails to render), so all of them are sent with the widget's defaults.
     */
    public static function widgetConfig(): array
    {
        $text = ['project' => null, 'global' => null, 'builtin' => null];

        return [
            'position' => 'bottom-right',
            'theme' => 'auto',
            'hidePoweredBy' => true,
            'buttonText' => $text,
            'buttonShape' => 'round',
            'buttonIcon' => 'bug',
            'buttonIconSize' => 18,
            'buttonIconStroke' => 2,
            'lightButtonColor' => '#02658D',
            'lightTextColor' => '#ffffff',
            'lightButtonHoverColor' => '#024F6F',
            'lightTextHoverColor' => '#ffffff',
            'darkButtonColor' => '#02658D',
            'darkTextColor' => '#ffffff',
            'darkButtonHoverColor' => '#036F9B',
            'darkTextHoverColor' => '#ffffff',
            'dialogLightButtonColor' => '#02658D',
            'dialogLightTextColor' => '#ffffff',
            'dialogLightButtonHoverColor' => '#024F6F',
            'dialogLightTextHoverColor' => '#ffffff',
            'dialogLightBackgroundColor' => '#ffffff',
            'dialogLightSecondaryColor' => '#f5f5f5',
            'dialogLightInputColor' => '#ffffff',
            'dialogLightForegroundColor' => '#0a0a0a',
            'dialogDarkButtonColor' => '#02658D',
            'dialogDarkTextColor' => '#ffffff',
            'dialogDarkButtonHoverColor' => '#036F9B',
            'dialogDarkTextHoverColor' => '#ffffff',
            'dialogDarkBackgroundColor' => '#0a0a0a',
            'dialogDarkSecondaryColor' => '#262626',
            'dialogDarkInputColor' => '#1a1a1a',
            'dialogDarkForegroundColor' => '#fafafa',
            'enableHoverScaleEffect' => true,
            'tooltipEnabled' => false,
            'tooltipText' => $text,
            'captureMethod' => 'visible',
            'useScreenCaptureAPI' => false,
            'language' => ['mode' => 'auto', 'defaultLanguage' => 'en'],
            'features' => [
                'screenshot' => true,
                'annotation' => true,
                'attachments' => true,
                'consoleCapture' => true,
                'networkCapture' => true,
                'storageKeysCapture' => false,
                'userActivityCapture' => 'disabled',
            ],
            'maxScreenshotSizeMb' => self::MAX_FILE_SIZE / 1048576,
            'maxImageUploadSizeMb' => self::MAX_FILE_SIZE / 1048576,
            'maxVideoUploadSizeMb' => self::MAX_FILE_SIZE / 1048576,
        ];
    }

    /**
     * Counts the reports of an IP; false once it has sent RATE_LIMIT of them within RATE_WINDOW seconds.
     */
    public static function allowRequest(string $ip): bool
    {
        if (!Yii::$app->has('cache')) {
            return true;
        }
        $cache = Yii::$app->cache;
        $key = ['feedback-rate', $ip];
        $count = (int) $cache->get($key);
        if ($count >= self::RATE_LIMIT) {
            return false;
        }
        $cache->set($key, $count + 1, self::RATE_WINDOW);

        return true;
    }

    /**
     * Writes report.json and the media files into a new report directory and returns its id.
     *
     * @param UploadedFile[] $files
     * @throws \InvalidArgumentException when the report or one of the files is not accepted
     */
    public static function save(string $json, array $files): string
    {
        if ($json === '' || strlen($json) > self::MAX_DATA_SIZE) {
            throw new \InvalidArgumentException('Missing or too large report data');
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Report data is not valid JSON');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            throw new \InvalidArgumentException('Title is required (at most 200 characters)');
        }
        if (count($files) > self::MAX_FILES) {
            throw new \InvalidArgumentException('At most ' . self::MAX_FILES . ' files are accepted');
        }
        $media = [];
        foreach ($files as $file) {
            $media[] = ['file' => $file, 'ext' => self::check($file)];
        }

        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $dir = Yii::getAlias(self::$basePath) . '/' . $id;
        FileHelper::createDirectory($dir);
        $names = [];
        foreach ($media as $i => $item) {
            $name = 'media-' . $i . '.' . $item['ext'];
            if (!$item['file']->saveAs($dir . '/' . $name, false)) {
                throw new \RuntimeException('Could not store ' . $name);
            }
            $names[] = $name;
        }
        $data['title'] = $title;
        $report = [
            'id' => $id,
            'received' => date('c'),
            'ip' => Yii::$app->request instanceof \yii\web\Request ? Yii::$app->request->userIP : null,
            'media' => $names,
            'report' => $data,
        ];
        file_put_contents($dir . '/report.json', Json::encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $id;
    }

    /**
     * Mails a short summary of the report, with its images attached, to the feedback::mail addresses.
     */
    public static function mail(string $id): bool
    {
        $to = self::recipients();
        $dir = Yii::getAlias(self::$basePath) . '/' . $id;
        if ($to === [] || !is_file($dir . '/report.json')) {
            return false;
        }
        $mailer = Yii::$app->has('site') && isset(Yii::$app->site->mailer)
            ? Yii::$app->site->mailer
            : (Yii::$app->has('mailer') ? Yii::$app->mailer : null);
        if ($mailer === null) {
            return false;
        }
        $saved = Json::decode(file_get_contents($dir . '/report.json'));
        $data = $saved['report'];
        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $lines = [
            'Title: ' . $data['title'],
            'Priority: ' . ($data['priority'] ?? '-'),
            'Reporter: ' . trim(($data['reporterName'] ?? '') . ' <' . ($data['reporterEmail'] ?? '') . '>'),
            'Page: ' . ($meta['url'] ?? '-'),
            'Browser: ' . trim(($meta['browser']['name'] ?? '') . ' ' . ($meta['browser']['version'] ?? '')),
            'Viewport: ' . ($meta['viewport']['width'] ?? '?') . 'x' . ($meta['viewport']['height'] ?? '?'),
            'Console errors: ' . count((array) ($meta['consoleErrors'] ?? [])),
            'Network errors: ' . count((array) ($meta['networkErrors'] ?? [])),
            'Report: ' . $id,
            '',
            (string) ($data['description'] ?? ''),
        ];

        try {
            $message = $mailer->compose()
                ->setTo($to)
                ->setSubject('[Bug report] ' . $data['title'])
                ->setTextBody(implode("\n", $lines));
            $from = (string) Yii::$app->setting->getValue('email::address');
            if ($from !== '') {
                $message->setFrom([$from => (string) Yii::$app->setting->getValue('email::displayname')]);
            }
            foreach ($saved['media'] as $name) {
                if (preg_match('/\.(png|jpg|webp)$/', $name)) {
                    $message->attach($dir . '/' . $name);
                }
            }

            return (bool) $mailer->send($message);
        } catch (\Throwable $e) {
            Yii::error(['report' => $id, 'error' => $e->getMessage()], 'feedback');

            return false;
        }
    }

    /**
     * @return string[]
     */
    public static function recipients(): array
    {
        $list = preg_split('/[\s,;]+/', (string) self::setting(self::MAIL), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($list, static function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }));
    }

    /**
     * The setting's value, or null while its row is missing (Setting::getValue throws then, and this runs on
     * every page).
     */
    private static function setting(string $name)
    {
        try {
            return Yii::$app->setting->getValue($name);
        } catch (\yii\web\NotFoundHttpException $e) {
            return null;
        }
    }

    private static function check(UploadedFile $file): string
    {
        if ($file->error !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Upload failed: ' . $file->name);
        }
        if ($file->size > self::MAX_FILE_SIZE || filesize($file->tempName) > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File is larger than 10 MB: ' . $file->name);
        }
        $type = FileHelper::getMimeType($file->tempName, null, false);
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('File type is not accepted: ' . $file->name);
        }

        return self::TYPES[$type];
    }
}
