<?php

declare(strict_types=1);

namespace portalium\feedback\tests\components;

use portalium\feedback\bundles\BugPinAsset;
use portalium\feedback\components\BugPin;
use tests\support\TestCase;
use Yii;
use yii\helpers\FileHelper;
use yii\web\IdentityInterface;
use yii\web\UploadedFile;

/**
 * Shared setup of the BugPin tests: a web application with a fake setting, an optional signed-in user, a file
 * mailer and a temporary report directory.
 */
abstract class BugPinTestCase extends TestCase
{
    public const KEY = 'test-key-0123456789abcdef';

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/bugpin-test-' . bin2hex(random_bytes(4));
        FileHelper::createDirectory($this->tmp);
        BugPin::$basePath = $this->tmp . '/reports';
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->tmp);
        BugPin::$basePath = '@runtime/feedback';
        $_FILES = [];
        UploadedFile::reset();
        parent::tearDown();
    }

    protected function boot(string $key, string $mail, bool $signedIn): void
    {
        $this->mockWebApplication([
            'components' => [
                'user' => [
                    'class' => \yii\web\User::class,
                    'identityClass' => \portalium\user\models\User::class,
                    'enableSession' => false,
                ],
                'assetManager' => ['bundles' => [BugPinAsset::class => false]],
                'cache' => ['class' => \yii\caching\ArrayCache::class],
                'mailer' => [
                    'class' => \yii\symfonymailer\Mailer::class,
                    'useFileTransport' => true,
                    'fileTransportPath' => $this->tmp . '/mail',
                ],
            ],
        ]);
        Yii::$app->set('setting', new class ([BugPin::KEY => $key, BugPin::MAIL => $mail, 'email::address' => 'suite@example.com']) {
            public function __construct(private array $values)
            {
            }

            public function getValue($name)
            {
                return $this->values[$name] ?? null;
            }
        });
        if ($signedIn) {
            Yii::$app->user->setIdentity(new class implements IdentityInterface {
                public $username = 'ada';
                public $email = 'ada@example.com';

                public static function findIdentity($id)
                {
                    return null;
                }

                public static function findIdentityByAccessToken($token, $type = null)
                {
                    return null;
                }

                public function getId()
                {
                    return 1;
                }

                public function getAuthKey()
                {
                    return '';
                }

                public function validateAuthKey($authKey)
                {
                    return false;
                }
            });
        }
    }

    protected function upload(string $content, string $name): UploadedFile
    {
        $path = $this->tmp . '/' . $name;
        file_put_contents($path, $content);

        return new UploadedFile([
            'name' => $name,
            'tempName' => $path,
            'type' => 'image/png',
            'size' => strlen($content),
            'error' => UPLOAD_ERR_OK,
        ]);
    }

    protected function json(): string
    {
        return json_encode([
            'title' => 'Button does nothing',
            'description' => 'Clicked Save on the app page',
            'priority' => 'medium',
            'metadata' => [
                'url' => 'http://visu/app/default/view?id=1',
                'browser' => ['name' => 'Chrome', 'version' => '130'],
                'viewport' => ['width' => 1440, 'height' => 900],
                'consoleErrors' => [['type' => 'error', 'message' => 'x is undefined']],
            ],
        ]);
    }

    protected static function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
}
