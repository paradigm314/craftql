<?php

namespace markhuot\CraftQL\Controllers;

use Craft;
use craft\web\Controller;
use markhuot\CraftQL\CraftQL;
use markhuot\CraftQL\Models\Token;

class ApiController extends Controller
{
    protected $allowAnonymous = ['index'];

    private $graphQl;
    private $request;

    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        // disable csrf
        $this->enableCsrfValidation = false;

        return parent::beforeAction($action);
    }

    function actionDebug() {
        $instance = \markhuot\CraftQL\CraftQL::getInstance();

        $oldMode = \Craft::$app->getView()->getTemplateMode();
        \Craft::$app->getView()->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);
        $data = $this->getView()->renderPageTemplate('craftql/debug-input', [
            'uri' => $instance->getSettings()->uri,
        ]);
        \Craft::$app->getView()->setTemplateMode($oldMode);
        return $data;
    }

    function actionIndex()
    {
        $response = \Craft::$app->getResponse();
        $result = false;

        $authorization = Craft::$app->request->headers->get('authorization');
        preg_match('/^(?:b|B)earer\s+(?<tokenId>.+)/', $authorization, $matches);
        try {
            $token = Token::findOrAnonymous(@$matches['tokenId']);
        } catch ( \UnexpectedValueException
                | \Firebase\JWT\SignatureInvalidException
                | \Firebase\JWT\BeforeValidException
                | \Firebase\JWT\ExpiredException $e) {
            $response->setStatusCode(401);
            $response->headers->add('Content-Type', 'application/json; charset=UTF-8');
            return $this->asErrorJson($e->getMessage());
        }


        if ($user = $token->getUser()) {
            $response->headers->add('Authorization', 'Bearer ' . CraftQL::getInstance()->jwt->tokenForUser($user));
        }

        if ($allowedOrigins = CraftQL::getInstance()->getSettings()->allowedOrigins) {
            if (is_string($allowedOrigins)) {
                $allowedOrigins = [$allowedOrigins];
            }
            $origin = \Craft::$app->getRequest()->headers->get('Origin');
            if (in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
            }

            $response->headers->add('Access-Control-Allow-Credentials', 'true');
            $response->headers->add('Access-Control-Allow-Headers', 'Authorization, Content-Type');
            $response->headers->add('Access-Control-Expose-Headers', 'Authorization');
            $response->headers->set('Access-Control-Allow-Headers', implode(', ', CraftQL::getInstance()->getSettings()->allowedHeaders));
        }

        $response->headers->set('Allow', implode(', ', CraftQL::getInstance()->getSettings()->verbs));

        if (\Craft::$app->getRequest()->isOptions) {
            return '';
        }

        Craft::debug('CraftQL: Parsing request');
        if (Craft::$app->request->isPost && $query=Craft::$app->request->post('query')) {
            $input = $query;
        }
        else if (Craft::$app->request->isGet && $query=Craft::$app->request->get('query')) {
            $input = $query;
        }
        else {
            $data = Craft::$app->request->getRawBody();
            $data = json_decode($data, true);
            $input = @$data['query'];
        }

        if (Craft::$app->request->isPost && $query=Craft::$app->request->post('variables')) {
            $variables = $query;
        }
        else if (Craft::$app->request->isGet && $query=Craft::$app->request->get('variables')) {
            $variables = json_decode($query, true);
        }
        else {
            $data = Craft::$app->request->getRawBody();
            $data = json_decode($data, true);
            $variables = @$data['variables'];
        }

        $config = Craft::$app->getConfig()->getConfigFromFile('craftql');
        $useCache = key_exists('useCache', $config) && $config['useCache'] === true;

        if ($useCache) {
            Craft::debug('CraftQL: Checking cache');
            $cache    = Craft::$app->getCache();
            $cacheKey = $cache->buildKey([$input, $variables]);

            if ($cache->exists($cacheKey)) {
                $result = $cache->get($cacheKey);
            }
        }

        if(!$result) {
            Craft::debug('CraftQL: Parsing request complete');

            Craft::debug('CraftQL: Bootstrapping');
            CraftQL::getInstance()->graphQl->bootstrap();
            Craft::debug('CraftQL: Bootstrapping complete');

            Craft::debug('CraftQL: Fetching schema');
            $schema = CraftQL::getInstance()->graphQl->getSchema($token);
            Craft::debug('CraftQL: Schema built');

            Craft::debug('CraftQL: Executing query');

            $result = CraftQL::getInstance()->graphQl->execute($schema, $input, $variables);

            if($useCache) {
                Craft::debug('CraftQL: Updating cache');
                $cache->set($cacheKey, $result);
            }
        }

        Craft::debug('CraftQL: Execution complete');

        $customHeaders = CraftQL::getInstance()->getSettings()->headers ?: [];
        foreach ($customHeaders as $key => $value) {
            if (is_callable($value)) {
                $value = $value($schema, $input, $variables, $result);
            }
            $response = \Craft::$app->getResponse();
            $response->headers->add($key, $value);
        }

        if (!!Craft::$app->request->post('debug')) {
            $response = \Yii::$app->getResponse();
            $response->format = \craft\web\Response::FORMAT_HTML;

            $oldMode = \Craft::$app->getView()->getTemplateMode();
            \Craft::$app->getView()->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);
            $response->data = $this->getView()->renderPageTemplate('craftql/debug-response', ['json' => json_encode($result)]);
            \Craft::$app->getView()->setTemplateMode($oldMode);

            return $response;
        }

        // You must set the header to JSON, otherwise Craft will see HTML and try to insert
        // javascript at the bottom to run pending tasks
        $response = \Craft::$app->getResponse();
        $response->headers->add('Content-Type', 'application/json; charset=UTF-8');

        return $this->asJson($result);
    }
}
