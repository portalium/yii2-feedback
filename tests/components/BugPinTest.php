<?php

declare(strict_types=1);

namespace portalium\feedback\tests\components;

use portalium\feedback\bundles\BugPinAsset;
use portalium\feedback\components\BugPin;
use Yii;
use yii\base\Event;
use yii\web\NotFoundHttpException;
use yii\web\View;

/**
 * BugPin is the self-hosted receiver of the BugPin widget. The widget is put on full pages of signed-in users
 * once feedback::key holds a valid key; a report is written to @runtime/feedback/<id>/ (report.json + media)
 * and mailed with its images to the feedback::mail addresses. Files are checked by their content, not by the
 * name or type the browser sends.
 */
class BugPinTest extends BugPinTestCase
{
    /**
     * The signed-in user's name and email are not put into the form; its fields start empty.
     */
    public function testSignedInUserGetsTheWidgetWithEmptyReporterFields(): void
    {
        $this->boot(self::KEY, '', true);

        $view = $this->render();

        $head = implode("\n", $view->js[View::POS_HEAD] ?? []);
        $this->assertStringContainsString('"apiKey":"' . self::KEY . '"', $head);
        $this->assertStringNotContainsString('reporterEmail', $head);
        $this->assertStringNotContainsString('reporterName', $head);
        $this->assertStringContainsString('"captureOnOpen":true', $head);
        $this->assertStringContainsString('"capturePixelRatio":1', $head);
        $this->assertStringContainsString('"launcherStyle":"tab"', $head);
        $this->assertStringContainsString('"language":"', $head);
        $this->assertArrayHasKey(BugPinAsset::class, $view->assetBundles);
    }

    public function testLauncherPlacementComesFromTheModule(): void
    {
        $this->boot(self::KEY, '', true);
        Yii::$app->setModule('feedback', [
            'class' => \portalium\feedback\Module::class,
            'launcher' => 'button',
            'position' => 'bottom-right',
            'launcherOffset' => ['right' => 28, 'bottom' => 88],
        ]);

        $head = implode("\n", $this->render()->js[View::POS_HEAD] ?? []);

        $this->assertStringContainsString('"launcherStyle":"button"', $head);
        $this->assertStringContainsString('"position":"bottom-right"', $head);
        $this->assertStringContainsString('"launcherOffset":{"right":28,"bottom":88}', $head);
    }

    /**
     * The widget copies these fields over its defaults even when they are missing; buttonText undefined made the
     * launcher fail to render, so no button appeared.
     */
    public function testConfigCarriesEveryFieldTheWidgetCopies(): void
    {
        $config = BugPin::widgetConfig();
        $copied = [
            'buttonText', 'buttonShape', 'buttonIcon', 'buttonIconSize', 'buttonIconStroke', 'position', 'theme',
            'lightButtonColor', 'lightTextColor', 'lightButtonHoverColor', 'lightTextHoverColor',
            'darkButtonColor', 'darkTextColor', 'darkButtonHoverColor', 'darkTextHoverColor',
            'dialogLightButtonColor', 'dialogLightTextColor', 'dialogLightButtonHoverColor', 'dialogLightTextHoverColor',
            'dialogLightBackgroundColor', 'dialogLightSecondaryColor', 'dialogLightInputColor', 'dialogLightForegroundColor',
            'dialogDarkButtonColor', 'dialogDarkTextColor', 'dialogDarkButtonHoverColor', 'dialogDarkTextHoverColor',
            'dialogDarkBackgroundColor', 'dialogDarkSecondaryColor', 'dialogDarkInputColor', 'dialogDarkForegroundColor',
            'enableHoverScaleEffect', 'tooltipEnabled', 'tooltipText', 'captureMethod', 'useScreenCaptureAPI', 'language',
        ];

        foreach ($copied as $field) {
            $this->assertArrayHasKey($field, $config, $field);
            $this->assertNotNull($config[$field], $field);
        }
        $this->assertArrayHasKey('project', $config['buttonText']);
        $this->assertArrayHasKey('project', $config['tooltipText']);
    }

    public function testGuestEmptyOrShortKeyGetsNothing(): void
    {
        foreach ([[self::KEY, false], ['', true], ['short', true]] as [$key, $signedIn]) {
            $this->boot($key, '', $signedIn);
            $view = $this->render();
            $this->assertEmpty($view->js, "key '$key'");
            $this->assertArrayNotHasKey(BugPinAsset::class, $view->assetBundles);
            $this->destroyApplication();
        }
    }

    public function testMissingSettingRowGetsNothingInsteadOfAnError(): void
    {
        $this->boot(self::KEY, '', true);
        Yii::$app->set('setting', new class {
            public function getValue($name)
            {
                throw new NotFoundHttpException("The requested $name setting does not exist.");
            }
        });

        $view = $this->render();

        $this->assertEmpty($view->js, 'Before the SQL/migration runs, every page of a signed-in user would fail');
        $this->assertFalse(BugPin::accepts(self::KEY));
        $this->assertSame([], BugPin::recipients());
    }

    public function testAcceptsOnlyTheConfiguredKey(): void
    {
        $this->boot(self::KEY, '', false);

        $this->assertTrue(BugPin::accepts(self::KEY));
        $this->assertFalse(BugPin::accepts('test-key-0123456789abcdeX'));
        $this->assertFalse(BugPin::accepts(null));

        $this->boot('', '', false);
        $this->assertFalse(BugPin::accepts(''), 'An empty setting must not accept an empty key');
    }

    public function testSaveWritesReportAndMedia(): void
    {
        $this->boot(self::KEY, '', false);

        $id = BugPin::save($this->json(), [$this->upload(self::png(), 'shot.png')]);

        $dir = $this->tmp . '/reports/' . $id;
        $report = json_decode(file_get_contents($dir . '/report.json'), true);
        $this->assertSame('Button does nothing', $report['report']['title']);
        $this->assertSame(['media-0.png'], $report['media']);
        $this->assertFileEquals($this->tmp . '/shot.png', $dir . '/media-0.png');
    }

    public function testRejectsBadReports(): void
    {
        $this->boot(self::KEY, '', false);
        $cases = [
            'no data' => ['', []],
            'not json' => ['{', []],
            'no title' => ['{"title":"  "}', []],
            'php file named png' => [$this->json(), [$this->upload('<?php echo 1;', 'shot.png')]],
            'too many files' => [$this->json(), array_fill(0, BugPin::MAX_FILES + 1, $this->upload(self::png(), 'a.png'))],
        ];
        foreach ($cases as $name => [$json, $files]) {
            try {
                BugPin::save($json, $files);
                $this->fail("$name was accepted");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertDirectoryDoesNotExist($this->tmp . '/reports', 'A rejected report must not leave a directory');
    }

    public function testRejectsFileOverTheSizeLimit(): void
    {
        $this->boot(self::KEY, '', false);
        $file = $this->upload(self::png(), 'big.png');
        $file->size = BugPin::MAX_FILE_SIZE + 1;

        $this->expectException(\InvalidArgumentException::class);
        BugPin::save($this->json(), [$file]);
    }

    public function testMailGoesToEveryValidAddressWithTheScreenshot(): void
    {
        $this->boot(self::KEY, 'dev@example.com, not-an-address; qa@example.com', false);
        $id = BugPin::save($this->json(), [$this->upload(self::png(), 'shot.png')]);

        $this->assertTrue(BugPin::mail($id));

        $mails = glob($this->tmp . '/mail/*.eml');
        $this->assertCount(1, $mails);
        $mail = file_get_contents($mails[0]);
        $this->assertStringContainsString('dev@example.com', $mail);
        $this->assertStringContainsString('qa@example.com', $mail);
        $this->assertStringNotContainsString('not-an-address', $mail);
        $this->assertStringContainsString('Button does nothing', $mail);
        $this->assertStringContainsString('media-0.png', $mail);
    }

    /**
     * The mail is shaped for Trello's email-to-board address: the subject is the card title in the SUITE board's
     * "MAIL - ..." form with the kind as a #label (bug → #Bug, idea → #Feature, question → none). An unknown kind is
     * dropped instead of being stored.
     */
    public function testCategoryIsKeptAndLabelsTheMail(): void
    {
        $this->boot(self::KEY, 'dev@example.com', false);
        $data = json_decode($this->json(), true);
        $id = BugPin::save(json_encode(['category' => 'idea'] + $data), []);
        $odd = BugPin::save(json_encode(['category' => '<script>'] + $data), []);

        $this->assertTrue(BugPin::mail($id));
        $mail = quoted_printable_decode(file_get_contents(glob($this->tmp . '/mail/*.eml')[0]));
        $this->assertStringContainsString('Subject: MAIL - Button does nothing #Feature', $mail);
        $this->assertStringContainsString('**Tür:** Öneri', $mail);
        $this->assertSame('MAIL - Button does nothing #Bug', BugPin::mailSubject(['title' => 'Button does nothing', 'category' => 'bug']));
        $this->assertSame('MAIL - Why', BugPin::mailSubject(['title' => 'Why', 'category' => 'question']));
        $stored = json_decode(file_get_contents(Yii::getAlias(BugPin::$basePath) . "/$odd/report.json"), true);
        $this->assertArrayNotHasKey('category', $stored['report']);
    }

    /**
     * The body becomes the card description (Markdown): facts, the comment, then the first console errors and failed
     * requests themselves, each on one line and cut at 300 characters.
     */
    public function testMailBodyListsTheErrors(): void
    {
        $long = str_repeat('a', 400);
        $body = BugPin::mailBody([
            'id' => 'r1',
            'report' => [
                'title' => 'Save fails',
                'description' => "Save does nothing\nsecond line",
                'category' => 'bug',
                'reporterEmail' => 'ada@example.com',
                'metadata' => [
                    'url' => 'https://suite/app/default/index',
                    'browser' => ['name' => 'Chrome', 'version' => '142'],
                    'device' => ['os' => 'macOS'],
                    'viewport' => ['width' => 1440, 'height' => 900],
                    'consoleErrors' => [['type' => 'error', 'message' => "x is\nundefined"], ['type' => 'error', 'message' => $long]],
                    'networkErrors' => [['method' => 'POST', 'url' => '/api/app/1', 'status' => 500]],
                ],
            ],
        ]);

        $this->assertStringContainsString("**Tür:** Hata\n**Sayfa:** https://suite/app/default/index\n", $body);
        $this->assertStringContainsString('**Bildiren:** <ada@example.com>', $body);
        $this->assertStringContainsString('**Tarayıcı:** Chrome 142 · macOS · 1440×900', $body);
        $this->assertStringContainsString("Save does nothing\nsecond line", $body);
        $this->assertStringContainsString("**Konsol hataları (2)**\n- x is undefined\n- " . str_repeat('a', 300) . "…\n", $body);
        $this->assertStringContainsString("**Başarısız istekler (1)**\n- POST /api/app/1 → 500", $body);
    }

    public function testNoMailWithoutAddresses(): void
    {
        $this->boot(self::KEY, '', false);
        $id = BugPin::save($this->json(), []);

        $this->assertFalse(BugPin::mail($id));
        $this->assertSame([], glob($this->tmp . '/mail/*.eml') ?: []);
    }

    public function testRateLimitPerIp(): void
    {
        $this->boot(self::KEY, '', false);

        for ($i = 0; $i < BugPin::RATE_LIMIT; $i++) {
            $this->assertTrue(BugPin::allowRequest('10.0.0.1'));
        }
        $this->assertFalse(BugPin::allowRequest('10.0.0.1'));
        $this->assertTrue(BugPin::allowRequest('10.0.0.2'), 'Another IP has its own count');
    }

    private function render(): View
    {
        $view = new View();
        BugPin::onBeginBody(new Event(['sender' => $view]));

        return $view;
    }
}
