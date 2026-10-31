<?php

declare(strict_types=1);

namespace portalium\feedback\tests\controllers;

use portalium\feedback\components\BugPin;
use portalium\feedback\controllers\api\WidgetController;
use portalium\feedback\tests\components\BugPinTestCase;
use Yii;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;

/**
 * The two endpoints the BugPin widget calls. config answers only for the configured key; submit needs the key
 * in the x-api-key header, stores the multipart report ("data" JSON + "media[]" files) and answers with the
 * report id. Every failure is JSON {success:false, error} with a matching status code.
 */
class WidgetControllerTest extends BugPinTestCase
{
    public function testConfigForTheKey(): void
    {
        $this->boot(self::KEY, '', false);

        $body = $this->call('config', 'GET', ['key' => self::KEY]);

        $this->assertTrue($body['success']);
        $this->assertTrue($body['config']['features']['screenshot']);
        $this->assertSame(200, Yii::$app->response->statusCode);
    }

    public function testConfigForAnotherKeyIs404(): void
    {
        $this->boot(self::KEY, '', false);

        $body = $this->call('config', 'GET', ['key' => 'another-key-0123456789']);

        $this->assertFalse($body['success']);
        $this->assertSame(404, Yii::$app->response->statusCode);
    }

    public function testSubmitStoresTheReport(): void
    {
        $this->boot(self::KEY, '', false);
        $_SERVER['HTTP_X_API_KEY'] = self::KEY;
        $_POST['data'] = $this->json();
        $this->files([['shot-0.png', self::png()], ['shot-1.png', self::png()]]);

        $body = $this->call('submit', 'POST');

        $this->assertTrue($body['success'], json_encode($body));
        $report = json_decode(file_get_contents(Yii::getAlias(BugPin::$basePath) . '/' . $body['reportId'] . '/report.json'), true);
        $this->assertSame(['media-0.png', 'media-1.png'], $report['media'], 'Every media[] file is kept');
    }

    public function testSubmitWithoutTheKeyIs401(): void
    {
        $this->boot(self::KEY, '', false);
        $_SERVER['HTTP_X_API_KEY'] = 'wrong-key-0123456789abc';
        $_POST['data'] = $this->json();

        $body = $this->call('submit', 'POST');

        $this->assertFalse($body['success']);
        $this->assertSame(401, Yii::$app->response->statusCode);
    }

    public function testBadReportIs400(): void
    {
        $this->boot(self::KEY, '', false);
        $_SERVER['HTTP_X_API_KEY'] = self::KEY;
        $_POST['data'] = '{"title":""}';

        $body = $this->call('submit', 'POST');

        $this->assertFalse($body['success']);
        $this->assertSame(400, Yii::$app->response->statusCode);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_API_KEY'], $_SERVER['REQUEST_METHOD']);
        $_POST = [];
        parent::tearDown();
    }

    private function call(string $action, string $method, array $params = []): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $controller = new WidgetController('widget', Yii::$app);
        $result = $controller->runAction($action, $params);

        return $result instanceof \yii\web\Response ? $result->data : $result;
    }

    private function files(array $files): void
    {
        $dir = Yii::getAlias(BugPin::$basePath) . '-upload';
        FileHelper::createDirectory($dir);
        $media = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        foreach ($files as [$name, $content]) {
            file_put_contents($dir . '/' . $name, $content);
            $media['name'][] = $name;
            $media['type'][] = 'image/png';
            $media['tmp_name'][] = $dir . '/' . $name;
            $media['error'][] = UPLOAD_ERR_OK;
            $media['size'][] = strlen($content);
        }
        $_FILES = ['media' => $media];
        UploadedFile::reset();
    }
}
