<?php

namespace frontend\controllers;

use common\models\DocumentFieldsEnum;
use common\models\DocumentSignature;
use Faker\Provider\DateTime;
use Yii;
use common\models\DocumentUser;
use common\models\Document;
use common\models\DocumentFields;
use common\models\DocumentUserFields;
use yii\data\ActiveDataProvider;
use yii\helpers\Url;
use yii\httpclient\Exception;
use yii\log\Logger;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\web\Response;
//use kartik\mpdf\Pdf;
use yii\filters\AccessControl;
use common\models\User;
use \Knp\Snappy\Pdf;

/**
 * DocumentUserController implements the CRUD actions for DocumentUser model.
 */
class DocumentUserController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [

            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        //сюда попасть могут все. Если ссылка правильная
                        'allow' => true,
                        'roles' => ['?'],
                    ],
                    [
                        //остальное разрешаем только авторизованным
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],

            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    public function beforeAction($action) {

        if(in_array($action->id, ['protected-view', 'protected-sign'])) {
            Yii::$app->session->setFlash('user', 'Вам предоставлен доступ по ссылке');

            return parent::beforeAction($action);
        }

        return parent::beforeAction($action);
    }

    /**
     * Lists all DocumentUser models.
     * @return mixed
     */
    public function actionIndex()
    {
        $userId = User::getUserId();

        $dataProvider = new ActiveDataProvider([
            'query' => DocumentUser::find()->where(['user_id' => $userId]),
        ]);

        $userDocumentsList = [];
        // группируем документы по статусам
        foreach ($dataProvider->getModels() as $documentUser)
        {
            $userDocumentsList[$documentUser->status][] = $documentUser;
        }

        return $this->render('index', [
            'userDocumentsList' => $userDocumentsList,
            'showMyDocuments' => DocumentUser::find()->where(['user_id' => $userId])->count() > 0
        ]);
    }


    /**
     * Загрузка файла в pdf формате
     * @param $id
     * @return
     * @throws NotFoundHttpException
     * @throws \yii\web\HttpException
     */
    public function actionDownload($id)
    {

        $model = $this->findModel($id);
        if (!\Yii::$app->authManager->checkAccess(User::getUserId(), 'updateDocument',['document' => $model])) {
            return $this->render('forbidden.twig');
        }

        $snappy = new Pdf(Yii::getAlias("@root/vendor/h4cc/wkhtmltopdf-amd64/bin/wkhtmltopdf-amd64"));

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$model->document->name.'.pdf"'); // разница - attachment

        $content = $this->renderPartial("templates/{$model->document->code}/print",[
            'model' => $model,
            'fields' => $this->renderDocumentFields($model),
        ]);

        // некоторые документы имеют нестандартные размеры
        switch ($model->document->code)
        {
            case 'registracia-ip':
                $params = [
                    'margin-left' => '5',
                    'margin-right' => '5',
                    'margin-top' => '7',
                    'margin-bottom' => '7',
                ];
                break;
            default:
                $params = [];
                break;
        }

        // todo сохрннять файл если изменен. Иначе выдаем пользователю уже существующий
        //$snappy->generateFromHtml($content,  Yii::getAlias("@uploadPath/pdf/{$id}.pdf"));

        echo $snappy->getOutputFromHtml($content, array_merge($params,[
            'page-size' => 'A4',
            'encoding' => 'UTF-8',
//            'header-right' => 'Документ создан с помощью сервиса'
        ]));
    }

    /**
     * Открываем документ как pdf файл
     * @param $id
     * @return mixed
     * @throws NotFoundHttpException
     */
    public function actionPrint($id)
    {

        $model = $this->findModel($id);

        if (!\Yii::$app->authManager->checkAccess(User::getUserId(), 'updateDocument',['document' => $model])) {
            return $this->render('forbidden.twig', ['auth' => false]);
        }

        $snappy = new Pdf(Yii::getAlias("@root/vendor/h4cc/wkhtmltopdf-amd64/bin/wkhtmltopdf-amd64"));
        $snappy->setTemporaryFolder(Yii::getAlias("@frontend/upload/tmp"));

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="'.$model->document->name.'.pdf"');

        $content = $this->renderPartial("templates/{$model->document->code}/print",[
            'model' => $model,
            'fields' => $this->renderDocumentFields($model),
        ]);

        // некоторые документы имеют нестандартные размеры
        switch ($model->document->code)
        {
            case 'registracia-ip':
                $params = [
                    'margin-top' => '15',
                ];
                break;
            default:
                $params = [
                    'margin-left' => '12', // ширина установлена такая же как в предварительном просмотре, это на пару пикселей уже, выравниваем по центру
                ];
                break;
        }

        // todo сохрннять файл если изменен. Иначе выдаем пользователю уже существующий. Нет смысла генерить
        //$snappy->generateFromHtml($content,  Yii::getAlias("@uploadPath/pdf/{$id}.pdf"));

        echo $snappy->getOutputFromHtml($content, array_merge($params,[
            'page-size' => 'A4',
            'encoding' => 'UTF-8',
        ]));
    }



    /**
     * Displays a single DocumentUser model.
     * @param integer $id
     * @return mixed
     */
    public function actionView($id)
    {

        $model = $this->findModel($id);
        $document = $model->document;

        if (!\Yii::$app->authManager->checkAccess(User::getUserId(), 'updateDocument',['document' => $model])) {
            return $this->render('forbidden.twig');
        }

        $documentUserFields = $this->renderDocumentFields($model);

        return $this->render('view', [
            'model' => $this->findModel($id),
            'document' => $document,
            'documentUserFields' => $documentUserFields,
        ]);
    }

    /**
     * отображаем документ для внешнего пользователя (пришел по расшаренной ссылке)
     * @param $hash
     * @return string
     * @throws NotFoundHttpException
     */
    public function actionProtectedView($hash)
    {
        $model = $this->findModelByHash($hash);
        $document = $model->document;

        $documentUserFields = $this->renderDocumentFields($model);

        return $this->render('view', [
            'topPanel' => false,
            'model' => $model,
            'document' => $document,
            'documentUserFields' => $documentUserFields,
        ]);
    }

    /**
     * отображаем документ для подписания
     *
     * @param $hash
     *
     * @return string
     * @throws ForbiddenHttpException
     */
    public function actionProtectedSign($hash)
    {
        $signatureRequest = \common\models\DocumentSignatureRequest::find()->where(['hash' => $hash])->one();
        if(!$signatureRequest){
            throw new ForbiddenHttpException('Access denied');
        }

        $model = DocumentUser::findOne($signatureRequest->document_user_id);

        $document = $model->document;
        $documentUserFields = $this->renderDocumentFields($model);

        return $this->render('sign', [
            'model' => $model,
            'document' => $document,
            'hash'=> $hash,
            'documentUserFields' => $documentUserFields,
        ]);
    }

    public function actionSendSignatureRequest($id)
    {
        $request = Yii::$app->request;
        if ($request->post() && $request->isAjax)
        {
            $documentSignatureRequest = new \common\models\DocumentSignatureRequest;
            $documentSignatureRequest->load($request->post());
            $documentSignatureRequest->document_user_id = $id;
            $documentSignatureRequest->date = date('Y-m-d H:i:s');

            if($documentSignatureRequest->save())
            {
                mail($documentSignatureRequest->email, 'sendSignatureRequest', "доступ к документу $id открыт. Ссылка " . Url::to(['document-user/protected-sign/', 'hash' => $documentSignatureRequest->hash], true));
                return true;
            }
            else
            {
                return false;
            }
        }
        else
        {
            throw new ForbiddenHttpException('Access denied, Ajax post only');
        }
    }


    public function actionResetSignatureRequest($id)
    {

        $request = Yii::$app->request;
        if ($request->post() && $request->isAjax)
        {
            $documentSignatureRequest = \common\models\DocumentSignatureRequest::deleteAll('document_user_id = :id', [':id' => $id]);

            mail('maxlyaptsev@yandex.ru', 'debug sendSignatureRequest', "доступа к документу $id больше нет");
            return true;
        }
        else
        {
            throw new ForbiddenHttpException('Access denied');
        }
    }


    /**
     * Получить массив из DocumentUserFields, ключ которых код DocumentFields
     * Поддерживаются множественные значения, записанные в виде сериализованного массива
     * Поддерживаются перечисления, значения ссылаются на объект DocumentFieldsEnum
     * @param $document
     *
     * @return array
     */
    protected function renderDocumentFields($document)
    {
        // получаем поля для этого документа.
        $documentFields = DocumentFields::find()->where(['document_id' => $document->document->id])->all();
        $documentUserFields = [];
        // заполняем поля значениями пользовательского документа, ключ - код
        foreach ($documentFields as $key => $documentField)
        {

            /* @var $documentField DocumentFields */
            $documentUserFields[$documentField->code] = $documentField->getDocumentUserFields()
                ->with('documentField')
                ->where(['document_field_id' => $documentField->id, 'document_user_id' => $document->id])
                ->one();

            // пустые значения
            if($documentUserFields[$documentField->code] == null) {
                $documentUserFields[$documentField->code] = new DocumentUserFields();
            }

            // т.к. это множественное свойство, значения сериализованы
            if($documentField->multiple) {
                $documentUserFields[$documentField->code]->value = unserialize($documentUserFields[$documentField->code]->value);
            }

            // получаем значения перечислений (при соответствующем типе поля)
            if($documentField->type == DocumentFields::TYPE_ENUM) {
                if($documentField->multiple) {
                    $documentUserFields[$documentField->code]->value = DocumentFieldsEnum::find()->where(['id' => $documentUserFields[$documentField->code]->value])->all();

                } else {
                    $documentUserFields[$documentField->code]->value = DocumentFieldsEnum::find()->where(['id' => $documentUserFields[$documentField->code]->value])->one();
                }
            }


            if(!$documentUserFields[$documentField->code])
            {
                $documentUserFields[$documentField->code] = new DocumentUserFields();
            }

            /* @deprecated use related documentField */
            $documentUserFields[$documentField->code]->field = $documentField;

        }
        return $documentUserFields;

    }

    /**
     * Создаем новый пользовательский документ
     * Если создали, переходим к его редактированию - actionUpdate
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     */
    public function actionCreate($code)
    {
        $documentId = Document::find()->where(['code' => $code])->one()->id;

        // создаем новый документ
        $documentUser = new DocumentUser();

        // заполняем начальные данные документа. Привязываем к пользователю и к типу документа
        $documentUser->document_id = $documentId;
        $documentUser->date_create = Yii::$app->formatter->asDatetime('now', 'Y-M-d H:i:s');
        $documentUser->date_update = Yii::$app->formatter->asDatetime('now', 'Y-M-d H:i:s');
        $documentUser->user_id = User::getId();

        if($documentUser->validate())
        {
            // сохраняем документ и переходим к редактированию
            $documentUser->save();
            return $this->redirect(['update', 'id' => $documentUser->id]);
        }
        else
        {
            // ошибка добавления нового документа. Редиректим на список. Такого быть не должно.
            // TODO: добавить логирование
            return $this->redirect(['document-groups/index/']);
        }
    }


    /**
     * Updates an existing DocumentUser model.
     * If update is successful, the browser will be redirected to the 'view' page.
     *
     * @param integer $id
     *
     * @return mixed
     * @throws NotFoundHttpException
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        if (!\Yii::$app->authManager->checkAccess(User::getUserId(), 'updateDocument',['document' => $model])) {
            return $this->render('forbidden.twig');
        }

        $documentUserFields = $this->renderDocumentFields($model);

        // отображаем форму
        return $this->render('update', [
            'model' => $model,
            'document' => $model->document,
            'documentUserFields' => $documentUserFields,
            'fields' => $documentUserFields,
        ]);
    }

    /**
     * сохраняем поля пришедшие из формы. POST DocumentUserFields[PLACE][value]:Москва
     *
     * @param $id
     *
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     */
    public function actionUpdateFields($id)
    {

        $request = Yii::$app->request;

        if ($request->post() && $request->isAjax) {
            $documentUser = $this->findModel($id);

            if (!\Yii::$app->authManager->checkAccess(User::getUserId(), 'updateDocument',['document' => $documentUser])) {
                throw new ForbiddenHttpException('Access denied for user ' . User::getUserId());
            }

            // получаем поля для этого документа.
            $documentFields = DocumentFields::find()->where(['document_id' => $documentUser->document->id])->all();

            // сохраняем связанные поля. Проходимо циклом по всем доступным полям (чтобы не обрабатывать несуществующие поля)
            // todo предыдущий комент не понятен. разве нельзя сохранить только то поле что пришло?
            foreach ($documentFields  as $documentField) {

                // получаем новое значение поля
                $post = $request->post();

                // пришло новое значение для поля
                if(isset($post['DocumentUserFields'][$documentField->code])) {
                    // это может быть и массив
                    $value = $post['DocumentUserFields'][$documentField->code]['value'];
                }
                // иначе пропускаем
                else {
                    continue;
                }

                if(isset($value)) {
                    // возможно, заполенное поле уже есть, получаем его
                    $documentUserField = DocumentUserFields::find()->where(['document_user_id' => $id, 'document_field_id' => $documentField->id])->one();

                    // если поле документа еще не создано - поле не заполнялось
                    if(!is_object($documentUserField)) {
                        // cоздаем новое поле текущего документа
                        $documentUserField = new DocumentUserFields();
                        $documentUserField->document_user_id = $id;
                        $documentUserField->document_field_id = $documentField->id;
                    }

                    // множественный список
                    if(is_array($value)) {
                        $value = serialize($value);
                    }

                    $documentUserField->value = $value;

                else {
                        $documentUserField->save();

                        // обновляем дату изменения документа
                        $documentUser->date_update = Yii::$app->formatter->asDatetime('now', 'Y-M-d H:i:s');
                        $documentUser->save();
                    }

                }

            }

        }
        else {
            throw new ForbiddenHttpException('only ajax request');
        }

    }


    /**
     * Deletes an existing DocumentUser model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws \yii\db\StaleObjectException
     */
    public function actionDelete($id)
    {
        $model = $this->findModel($id);

        if (!\Yii::$app->authManager->checkAccess(User::getUserId(), 'updateDocument',['document' => $model])) {
            throw new ForbiddenHttpException('У вас нет прав удалять чужие документы');
        }

        $this->findModel($id)->delete();

    }

    /**
     * Сохраняем подпись, картинка должна прийти в post запросе
     * Ajax only
     * @return
     */
    public function actionSignature()
    {
        if(Yii::$app->request->isAjax && Yii::$app->request->isPost)
        {
            $post = Yii::$app->request->post();

            $documentUser = DocumentUser::find()->where(['id' => $post['document_user_id']])->one();
//            TODO перед записью подписи дополнительно проверять доступ
//            if (!\Yii::$app->user->can('ownerDocument', ['document' => $documentUser])) {
//                throw new ForbiddenHttpException('Access denied');
//            }

            // определяем тип пользователя

            $userType = DocumentSignature::TYPE_SIGNATORY;
            if(isset($post['user_id']))
            {
                // пользователь - создатель документа
                if($documentUser->user_id == $post['user_id'])
                {
                    $userType = DocumentSignature::TYPE_OWNER;
                }
            }

            // пытаемся найти существующую подпись
            $signatureQuery = DocumentSignature::find()->where(['document_user_id' => $post['document_user_id'], 'user' => $userType]);
            $signature = $signatureQuery->one();

            // подписи нет, создаем новый объект
            if($signatureQuery->count() == 0)
            {
                $signature = new DocumentSignature();
            }

            //тут сохраняем подпись, проверяем создался ли файл
            // TODO удалить старую подпись перед сохранением новой
            $imageSrc = $signature->saveSignatureImage($signature, $post['signature']);
            $signature->document_user_id = $post['document_user_id'];
            $signature->user = $userType;
            $signature->path = $imageSrc;
            if($signature->save())
            {
                $response = true;
            }
            else {
                $response = false;
            }

            return json_encode($response);
        }

        return new ForbiddenHttpException('Only ajax requests allow');

    }

    /**
     * Возвращает ссылку на страницу простмотра документа
     * Результат вставляется в input
     * @param $id
     * @return false|null|string
     * @throws NotFoundHttpException
     * @throws \HttpRequestException
     */
    public function actionUpdateHash($id)
    {

        //todo проверить доступ к договору
        $model = $this->findModel($id);

        if(Yii::$app->request->post('action') == 'remove')
        {
            $model->removeHash();
            return '';
        }
        elseif(Yii::$app->request->post('action') == 'update')
        {
            $model->updateHash();
            return Url::to(["document-user/protected-view/", 'hash' => $model->hash], true);
        }

        throw new \HttpRequestException('Только ajax запросы');

    }



    /**
     * Finds the DocumentUser model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return DocumentUser the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = DocumentUser::findOne($id)) !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }

    /**
     * Получаем модель по ее hash параметру (для расшаренных документов)
     * @param $hash
     * @return null|static
     * @throws NotFoundHttpException
     */
    protected function findModelByHash($hash)
    {
        if (($model = DocumentUser::findOne(['hash' => $hash])) !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }
}
