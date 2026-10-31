<?php

namespace portalium\feedback\controllers\api;

use portalium\feedback\components\BugPin;
use portalium\rest\Controller as RestController;
use Yii;
use yii\web\UploadedFile;

/**
 * The two endpoints the BugPin widget calls: GET /api/widget/config/<key> and POST /api/widget/submit
 * (x-api-key header, multipart "data" JSON + "media[]" files). The key is the feedback::key setting.
 */
class WidgetController extends RestController
{
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        unset($behaviors['authenticator']);

        return $behaviors;
    }

    protected function verbs()
    {
        return [
            'config' => ['GET'],
            'submit' => ['POST'],
        ];
    }

    public function actionConfig($key)
    {
        if (!BugPin::accepts($key)) {
            return $this->fail(404, 'Unknown key');
        }

        return ['success' => true, 'config' => BugPin::widgetConfig()];
    }

    public function actionSubmit()
    {
        $request = Yii::$app->request;
        if (!BugPin::accepts($request->headers->get('x-api-key'))) {
            return $this->fail(401, 'Invalid API key');
        }
        if (!BugPin::allowRequest((string) $request->userIP)) {
            return $this->fail(429, 'Too many reports, try again later');
        }
        try {
            $id = BugPin::save((string) $request->post('data', ''), UploadedFile::getInstancesByName('media'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail(400, $e->getMessage());
        }
        BugPin::mail($id);

        return ['success' => true, 'reportId' => $id];
    }

    private function fail(int $status, string $error): array
    {
        Yii::$app->response->statusCode = $status;

        return ['success' => false, 'error' => $error, 'message' => $error];
    }
}
