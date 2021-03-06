<?php
namespace wheelform\controllers;

use Craft;
use craft\web\Controller;
use craft\helpers\Path;
use craft\web\UploadedFile;

use wheelform\Plugin;
use wheelform\models\Form;
use wheelform\models\FormField;
use wheelform\helpers\ExportHelper;
use wheelform\models\tools\ImportFile;

use yii\base\Exception;
use yii\web\HttpException;
use yii\web\Response;
use Yii;

class FormController extends Controller
{

    function actionIndex()
    {
        $formModels = Form::find()->orderBy(['dateCreated' => SORT_ASC])->all();
        $forms = [];
        $user = Craft::$app->getUser();

        foreach($formModels as $formModel) {
            if($user->checkPermission('wheelform_edit_form_' . $formModel->id)) {
                $forms[] = $formModel;
            }
        }

        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('wheelform', 'Plugin settings need to be configured.'));
        }

        return $this->renderTemplate('wheelform/_index.twig', [
            'wheelforms' => $forms,
            'title' => $settings->cp_label,
        ]);
    }

    function actionNew()
    {
        $this->requirePermission('wheelform_new_form');

        $form = new Form();
         // Render the template
         return $this->renderTemplate('wheelform/_edit-form.twig', [
            'form' => $form
        ]);
    }

    function actionEdit()
    {
        $params = Craft::$app->getUrlManager()->getRouteParams();
        $form = null;

        if (! empty($params['id'])) {
            $form = Form::findOne(intval($params['id']));
        } elseif(! empty($params['form'])) {
            $form = $params['form'];
        }

        if (! $form) {
            throw new HttpException(404);
        }

        $this->requirePermission('wheelform_change_settings_' . $form->id);

        // Render the template
        return $this->renderTemplate('wheelform/_edit-form.twig', [
            'form' => $form
        ]);
    }

    public function actionSave()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();

        $data = json_decode($request->getRawBody(), TRUE);
        if (! empty($data['id'])) {
            $form = Form::findOne(intval($data['id']));
            if (! $form) {
                throw new Exception(Craft::t('wheelform', 'No form exists with the ID “{id}”.', array('id' => $form->id)));
            }
        } else {
            $form = new Form();
        }

        $form->name = $data['name'];
        $form->to_email = $data['to_email'];
        $form->active = $data['active'];
        $form->send_email = $data['send_email'];
        $form->recaptcha = $data['recaptcha'];
        $form->save_entry = $data['save_entry'];
        $form->options = $data['options'];
        $form->site_id = Craft::$app->sites->currentSite->id;

        $result = $form->save();

        if(! $result){
            return $this->asJson(['success' => false, 'errors' => $form->getErrors()]);
        }

        //Rebuild fields
        $oldFields = FormField::find()->select('id')->where(['form_id' => $form->id])->all();
        $newFields = $data['fields'];
        //Get ID of fields that are missing on the oldFields compared to newfields
        $toDeleteIds = $this->getToDeleteIds($oldFields, $newFields);

        if(! empty($newFields))
        {
            foreach($newFields as $field)
            {
                //If field name is empty skip it, but don't delete it, only delete it if delete icon is clicked.
                if(empty($field['name'])) continue;

                if(isset($field['id']) && intval($field['id']) > 0) {
                    //update Field Values
                    $formField = FormField::find()->where(['id' => $field['id']])->one();
                    if(! empty($formField))
                    {
                        $formField->setAttributes($field, false);

                        if($formField->validate()){
                            $formField->save();
                        }
                        else
                        {
                            //do nothing for now
                        }
                    }
                }
                else
                {
                    //unassign id to not autopopulate it on model; PostgreSQL doesn't like set ids
                    unset($field['id']);

                    // new field
                    $formField = new FormField();
                    $formField->setAttributes($field, false);

                    if($formField->save())
                    {
                        $form->link('fields', $formField);
                    }
                }
            }
        }

        if(! empty($toDeleteIds))
        {
            $db = Craft::$app->getDb();
            $db->createCommand()->update(
                FormField::tableName(),
                [
                    'active' => 0,
                    'required' => 0,
                    'index_view' => 0,
                ],
                "id IN (".implode(', ', $toDeleteIds).")"
            )->execute();
        }

        return $this->asJson(['success' => true, 'message' =>  Craft::t('wheelform', 'Form saved.'), 'form_id' => $form->id]);
    }

    // currently this field only accepts json fields
    public function actionGetSettings()
    {
        $req =  Craft::$app->getRequest();

        if(! $req->getAcceptsJson())
        {
            throw new HttpException(404);
        }

        $formId = $req->get('form_id');
        if(! is_numeric($formId) || empty($formId))
        {
            throw new HttpException(404);
        }

        $form = Form::find()->where(['id' => $formId])->with('fields')->asArray()->one();

        $response = Yii::$app->getResponse();
        $response->format = Response::FORMAT_JSON;
        $response->data = json_encode($form, JSON_NUMERIC_CHECK);

        return $response;
    }

    public function actionExportFields()
    {
        $req = Craft::$app->getRequest();

        if(! $req->getAcceptsJson())
        {
            throw new HttpException(404);
        }
        $params = $req->getRequiredBodyParam('params');
        $form_id = $params['form_id'];
        $where = [];

        if(empty($form_id))
        {
            throw new Exception(Craft::t('Form ID is required.', 'wheelform'));
        }

        try {
            $exportHelper = new ExportHelper();
            $where['form_id'] = $form_id;

            $jsonPath = $exportHelper->getFields($where);
        } catch (\Throwable $e) {
            throw new Exception('Could not create JSON: '.$e->getMessage());
        }

        if (!is_file($jsonPath)) {
            throw new Exception("Could not create JSON: the JSON file doesn't exist.");
        }

        $filename = pathinfo($jsonPath, PATHINFO_BASENAME);

        return $this->asJson([
            'jsonFile' => pathinfo($filename, PATHINFO_FILENAME)
        ]);
    }

    public function actionImportFields()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $req =  Craft::$app->getRequest();
        $form_id = $req->post('form_id', NULL);
        if(empty($form_id)) {
            return $this->asJson([
                'success' => false,
                'errors' => [Craft::t('wheelform', 'Form ID is required')],
            ]);
        }
        $fileModel = new ImportFile();
        $fileModel->jsonFile = UploadedFile::getInstanceByName('fields_file');

        if(! $fileModel->validate()) {
            return $this->asJson([
                'success' => false,
                'errors' => $fileModel->getErrors('jsonFile'),
            ]);
        }
        $temp = $fileModel->getTempPath();
        $jsonFields = file_get_contents($temp);
        $fields = json_decode($jsonFields, true);
        if(empty($jsonFields) || (json_last_error() !== JSON_ERROR_NONE)) {
            return $this->asJson([
                'success' => false,
                'errors' => [Craft::t('wheelform', 'Empty or invalid Json')],
            ]);
        }

        $errors = [];
        if(is_array($fields)) {
            foreach($fields as $f) {
                $fieldModel = new FormField();
                $f['form_id'] = $form_id;
                $fieldModel->setAttributes($f, false);
                if($fieldModel->validate()) {
                    $fieldModel->save();
                } else {
                    $errors = $fieldModel->getErrors();
                }
            }
        }

        return $this->asJson([
            'success' => Craft::t('wheelform', 'Fields Imported'),
            'errors' => $errors,
        ]);
    }

    public function actionDownloadFile()
    {
        $filename = Craft::$app->getRequest()->getRequiredQueryParam('filename');
        $filePath = Craft::$app->getPath()->getTempPath().DIRECTORY_SEPARATOR.$filename.'.json';

        if (!is_file($filePath) || !Path::ensurePathIsContained($filePath)) {
            throw new NotFoundHttpException(Craft::t('wheelform', 'Invalid json name: {filename}', [
                'filename' => $filename
            ]));
        }

        return Craft::$app->getResponse()->sendFile($filePath);
    }

    protected function getToDeleteIds(array $oldFields, array $newFields): array
    {
        $toDelete = [];

        if(! empty($oldFields))
        {
            foreach($oldFields as $field)
            {
                $index = array_search($field->id, array_column($newFields, 'id'));

                // $index is missing add field->id to toDelete array
                if($index === false)
                {
                    $toDelete[] = strval($field->id);
                }
            }
        }

        return $toDelete;
    }
}
