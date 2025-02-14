<?php

namespace Institution\Model\Table;

use ArrayObject;
use ZipArchive;
use DateTime;
use DateTimeZone;
use Cake\ORM\Query;
use Cake\ORM\Entity;
use Cake\ORM\TableRegistry;
use Cake\ORM\ResultSet;
use Cake\Event\Event;
use Cake\I18n\Time;
use Cake\I18n\Date;//POCOR-6841
use Cake\Log\Log;
use Cake\Datasource\ConnectionManager; //POCOR-6785
use App\Model\Table\ControllerActionTable;

class ReportCardStatusesTable extends ControllerActionTable
{
    private $statusOptions = [];
    private $reportProcessList = [];

    // for status
    CONST NEW_REPORT = 1;
    CONST IN_PROGRESS = 2;
    CONST GENERATED = 3;
    CONST PUBLISHED = 4;
    CONST ERROR = -1; //POCOR-6788

    CONST MAX_PROCESSES = 2;
    // POCOR-7321 start
    public $fileTypes = [
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'png' => 'image/png',
        // 'jpeg'=>'image/pjpeg',
        // 'jpeg'=>'image/x-png'
        'rtf' => 'text/rtf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'pdf' => 'application/pdf',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip'
    ];

    // POCOR-7321 end
    public function initialize(array $config)
    {
        $this->table('institution_class_students');
        parent::initialize($config);
        $this->belongsTo('Users', ['className' => 'User.Users', 'foreignKey' => 'student_id']);
        $this->belongsTo('InstitutionClasses', ['className' => 'Institution.InstitutionClasses']);
        $this->belongsTo('EducationGrades', ['className' => 'Education.EducationGrades']);
        $this->belongsTo('StudentStatuses', ['className' => 'Student.StudentStatuses']);
        $this->belongsTo('Institutions', ['className' => 'Institution.Institutions']);
        $this->belongsTo('AcademicPeriods', ['className' => 'AcademicPeriod.AcademicPeriods']);
        $this->belongsTo('NextInstitutionClasses', ['className' => 'Institution.InstitutionClasses', 'foreignKey' => 'next_institution_class_id']);
        $this->hasMany('InstitutionClassGrades', ['className' => 'Institution.InstitutionClassGrades']);

        $this->addBehavior('User.AdvancedNameSearch');

        $this->toggle('add', false);
        $this->toggle('edit', false);
        $this->toggle('remove', false);

        $this->ReportCards = TableRegistry::get('ReportCard.ReportCards');
        $this->StudentsReportCards = TableRegistry::get('Institution.InstitutionStudentsReportCards');
        $this->ReportCardEmailProcesses = TableRegistry::get('ReportCard.ReportCardEmailProcesses');
        $this->ReportCardProcesses = TableRegistry::get('ReportCard.ReportCardProcesses');

        $this->statusOptions = [
            self::NEW_REPORT => __('New'),
            self::IN_PROGRESS => __('In Progress'),
            self::GENERATED => __('Generated'),
            self::PUBLISHED => __('Published'),
            self::ERROR => __('Error') //POCOR-6788
        ];
    }

    public function implementedEvents()
    {
        $events = parent::implementedEvents();
        $events['ControllerAction.Model.generate'] = 'generate';
        $events['ControllerAction.Model.generateAll'] = 'generateAll';
        $events['ControllerAction.Model.downloadAll'] = 'downloadAll';
        $events['ControllerAction.Model.downloadAllPdf'] = 'downloadAllPdf';
        $events['ControllerAction.Model.mergeAnddownloadAllPdf'] = 'mergeAnddownloadAllPdf';   // POCOR-7320
        $events['ControllerAction.Model.viewPDF'] = 'viewPDF';//POCOR-7321
        $events['ControllerAction.Model.publish'] = 'publish';
        $events['ControllerAction.Model.publishAll'] = 'publishAll';
        $events['ControllerAction.Model.unpublish'] = 'unpublish';
        $events['ControllerAction.Model.unpublishAll'] = 'unpublishAll';
        $events['ControllerAction.Model.getSearchableFields'] = 'getSearchableFields';
        /**POCOR-6836 starts - modified existing functions and added new functions*/
        $events['ControllerAction.Model.emailPdf'] = 'emailPdf';
        $events['ControllerAction.Model.emailAllPdf'] = 'emailAllPdf';
        $events['ControllerAction.Model.emailExcel'] = 'emailExcel';
        $events['ControllerAction.Model.emailAllExcel'] = 'emailAllExcel';
        /**POCOR-6836 ends*/
        return $events;
    }

    public function onUpdateActionButtons(Event $event, Entity $entity, array $buttons)
    {
        $buttons = parent::onUpdateActionButtons($event, $entity, $buttons);

        // check if report card request is valid
        $reportCardId = $this->request->query('report_card_id');
        // POCOR-7998 refactored
        if (is_null($reportCardId)) {
            return $buttons;
        }
        $reportExists = $this->ReportCards->exists([$this->ReportCards->primaryKey() => $reportCardId]);
        if (!$reportExists) {
            return $buttons;
        }

        $indexAttr = ['role' => 'menuitem', 'tabindex' => '-1', 'escape' => false];
        $params = [
            'report_card_id' => $reportCardId,
            'student_id' => $entity->student_id,
            'institution_id' => $entity->institution_id,
            'academic_period_id' => $entity->academic_period_id,
            'education_grade_id' => $entity->education_grade_id,
        ];

        // Download button, status must be generated or published
        $canDownload = $this->AccessControl->check(['Institutions', 'InstitutionStudentsReportCards', 'download']);
        $reportHasStatus = $entity->has('report_card_status');



        if ($canDownload
            && $reportHasStatus
            && in_array($entity->report_card_status, [self::GENERATED, self::PUBLISHED])) {

            $buttons = $this->addDownloadExcelButton($buttons, $params);

            $buttons = $this->addDownloadPdfButton($buttons, $params);

            $buttons = $this->addViewPdfButton($buttons, $params);
        }

        //POCOR:6838 END
        $params['institution_class_id'] = $entity->institution_class_id;

        // Generate button, all statuses
        $buttons = $this->addGenerateButton($buttons, $params);

        // Publish button, status must be generated
        if ($this->AccessControl->check(['Institutions', 'ReportCardStatuses', 'publish'])
            && $reportHasStatus
            && ($entity->report_card_status == self::GENERATED
                || $entity->report_card_status == '12'
            )
        ) {
            $publishUrl = $this->setQueryString($this->url('publish'), $params);
            $buttons['publish'] = [
                'label' => '<i class="fa kd-publish"></i>' . __('Publish'),
                'attr' => $indexAttr,
                'url' => $publishUrl
            ];
        }

        // Unpublish button, status must be published
        if ($this->AccessControl->check(['Institutions', 'ReportCardStatuses', 'unpublish'])
            && $reportHasStatus
            && ($entity->report_card_status == self::PUBLISHED
                || $entity->report_card_status == '16'
            )
        ) {
            $unpublishUrl = $this->setQueryString($this->url('unpublish'), $params);
            $buttons['unpublish'] = [
                'label' => '<i class="fa kd-unpublish"></i>' . __('Unpublish'),
                'attr' => $indexAttr,
                'url' => $unpublishUrl
            ];
        }

        // Single email button, status must be published
        if ($this->AccessControl->check(['Institutions', 'ReportCardStatuses', 'emailPdf'])
            && $reportHasStatus
            && ($entity->report_card_status == self::PUBLISHED
                || $entity->report_card_status == '16'
            )
        ) {
            if (empty($entity->email_status_id) || ($entity->has('email_status_id') && $entity->email_status_id != $this->ReportCardEmailProcesses::SENDING)) {
                $emailUrl = $this->setQueryString($this->url('emailPdf'), $params);
                $buttons['emailPdf'] = [
                    'label' => '<i class="fa fa-envelope"></i>' . __('Email Pdf'),
                    'attr' => $indexAttr,
                    'url' => $emailUrl
                ];
            }
        }

        /** POCOR-6836 starts - Single email excel button, status must be published */
        if ($this->AccessControl->check(['Institutions', 'ReportCardStatuses', 'emailExcel'])
            && $reportHasStatus
            && ($entity->report_card_status == self::PUBLISHED
                || $entity->report_card_status == '16'
            )
        ) {
            if (empty($entity->email_status_id)
                || ($entity->has('email_status_id')
                    && $entity->email_status_id != $this->ReportCardEmailProcesses::SENDING)) {
                $emailUrl = $this->setQueryString($this->url('emailExcel'), $params);
                $buttons['emailExcel'] = [
                    'label' => '<i class="fa fa-envelope"></i>' . __('Email Excel'),
                    'attr' => $indexAttr,
                    'url' => $emailUrl
                ];
            }
        }
        /** POCOR-6836 ends*/
        return $buttons;
    }

    public function beforeAction(Event $event, ArrayObject $extra)
    {
        $this->field('openemis_no', ['sort' => ['field' => 'Users.openemis_no']]);
        $this->field('student_id', ['type' => 'integer', 'sort' => ['field' => 'Users.first_name']]);
        $this->field('report_card');
        $this->field('status', ['sort' => ['field' => 'report_card_status']]);
        $this->field('started_on');
        $this->field('completed_on');
        $this->field('email_status');
        $this->fields['next_institution_class_id']['visible'] = false;
        $this->fields['academic_period_id']['visible'] = false;
        $this->fields['student_status_id']['visible'] = false;
    }

    public function indexBeforeAction(Event $event, ArrayObject $extra)
    {
        //POCOR-7067 Starts
        $ConfigItems = TableRegistry::get('Configuration.ConfigItems');
        //POCOR-7581 start
        $ConfigItem = $ConfigItems
            ->find()
            ->select(['zonevalue' => 'ConfigItems.value'])
            ->where([
                $ConfigItems->aliasField('name') => 'Time Zone'
            ])
            ->first();
        $timeZone = $ConfigItem->zonevalue;
        if (empty($timeZone)) {
            $this->Alert->warning('ReportCardStatuses.timezone');
        }
        //POCOR-7581 end
        date_default_timezone_set($timeZone);//POCOR-7067 Ends
        //Start:POCOR-6785 need to convert this custom query to cake query
        $conn = ConnectionManager::get('default');
        $institutionId = $this->Session->read('Institution.Institutions.id');
        $ReportCardProcessesTable = TableRegistry::get('report_card_processes');
        $entitydata = $ReportCardProcessesTable->find('all', ['conditions' => [
            'institution_id' => $institutionId,
            'status !=' => '-1'
        ]])->where([$ReportCardProcessesTable->aliasField('modified IS NOT NULL')])->toArray();

        foreach ($entitydata as $keyy => $entity) {
            //POCOR-7067 Starts
            $now = new DateTime();
            $currentDateTime = $now->format('Y-m-d H:i:s');
            $c_timestap = strtotime($currentDateTime);
            $modifiedDate = $entity->modified;
            //POCOR-6841 starts
            if ($entity->status == 2) {
                //POCOR-6895: START

                //POCOR-6895: END
                $currentTimeZone = new DateTime();
                $modifiedDate = ($modifiedDate === null) ? $currentTimeZone : $modifiedDate;
                $m_timestap = strtotime($modifiedDate);
                $interval = abs($c_timestap - $m_timestap);
                $diff_mins = round($interval / 60);
                //POCOR-7535 start
                // if($diff_mins > 5 && $diff_mins < 30){
                //     $entity->status = 1;
                //     $ReportCardProcessesTable->save($entity);
                // }
                //POCOR-7535 end 
                if ($diff_mins > 30) {
                    $entity->status = self::ERROR; //(-1)
                    $entity->modified = $currentTimeZone;//POCOR-6841
                    $ReportCardProcessesTable->save($entity);
                    $StudentsReportCards = TableRegistry::get('Institution.InstitutionStudentsReportCards');
                    $StudentsReportCards->updateAll([
                        'status' => -1//POCOR-7530
                    ], ['student_id' => $entity->student_id, 'report_card_id' => $entity->report_card_id]);

                }//POCOR-7067 Ends
            }//POCOR-6841 ends
        }

        //POCOR-7496[START]
        // $stmtNew = $conn->query("UPDATE institution_students_report_cards INNER JOIN report_card_processes ON institution_students_report_cards.report_card_id = report_card_processes.report_card_id AND institution_students_report_cards.student_id = report_card_processes.student_id AND institution_students_report_cards.institution_id = report_card_processes.institution_id AND institution_students_report_cards.academic_period_id = report_card_processes.academic_period_id AND institution_students_report_cards.education_grade_id = report_card_processes.education_grade_id AND institution_students_report_cards.institution_class_id = report_card_processes.institution_class_id SET institution_students_report_cards.status = report_card_processes.status  where institution_students_report_cards.status In (1,2,3)");//POCOR-7383 added where condition for publish reports
        // $successQQ =$stmtNew->execute();
        //POCOR-7496[END]

        //END:POCOR-6785
        $this->field('report_queue');
        $this->setFieldOrder(['openemis_no', 'student_id', 'report_card', 'status', 'started_on', 'completed_on', 'report_queue', 'email_status']);

        // SQL Query to get the current processing list for report_queue table
        $this->reportProcessList = $this->ReportCardProcesses
            ->find()
            ->select([
                $this->ReportCardProcesses->aliasField('report_card_id'),
                $this->ReportCardProcesses->aliasField('institution_class_id'),
                $this->ReportCardProcesses->aliasField('student_id'),
                $this->ReportCardProcesses->aliasField('institution_id'),
                $this->ReportCardProcesses->aliasField('education_grade_id'),
                $this->ReportCardProcesses->aliasField('academic_period_id')
            ])
            ->where([
                $this->ReportCardProcesses->aliasField('status') => $this->ReportCardProcesses::NEW_REPORT //POCOR-7989
            ])
            ->order([
                $this->ReportCardProcesses->aliasField('created'),
                $this->ReportCardProcesses->aliasField('student_id')
            ])
            ->hydrate(false)
            ->toArray();
    }

    public function indexBeforeQuery(Event $event, Query $query, ArrayObject $extra)
    {
        $institutionId = $this->Session->read('Institution.Institutions.id');
        $Classes = $this->InstitutionClasses;
        $InstitutionGrades = TableRegistry::get('Institution.InstitutionGrades');

        // Academic Periods filter
        $academicPeriodOptions = $this->AcademicPeriods->getYearList(['isEditable' => true]);
        $selectedAcademicPeriod = !is_null($this->request->query('academic_period_id')) ? $this->request->query('academic_period_id') : $this->AcademicPeriods->getCurrent();
        $this->controller->set(compact('academicPeriodOptions', 'selectedAcademicPeriod'));
        $where[$this->aliasField('academic_period_id')] = $selectedAcademicPeriod;
        //End

        $availableGrades = $InstitutionGrades->find()
            ->where([$InstitutionGrades->aliasField('institution_id') => $institutionId])
            ->extract('education_grade_id')
            ->toArray();
        //print_r($availableGrades);die;

        // Report Cards filter
        $reportCardOptions = [];
        if (!empty($availableGrades)) {
            $reportCardOptions = $this->ReportCards->find('list')
                ->where([
                    $this->ReportCards->aliasField('academic_period_id') => $selectedAcademicPeriod,
                    $this->ReportCards->aliasField('education_grade_id IN ') => $availableGrades
                ])
                ->toArray();
        } else {
            $this->Alert->warning('ReportCardStatuses.noProgrammes');
        }

        $reportCardOptions = ['-1' => '-- ' . __('Select Report Card') . ' --'] + $reportCardOptions;
        $selectedReportCard = !is_null($this->request->query('report_card_id')) ? $this->request->query('report_card_id') : -1;
        $this->controller->set(compact('reportCardOptions', 'selectedReportCard'));
        //End

        // Class filter
        $classOptions = [];
        $selectedClass = !is_null($this->request->query('class_id')) ? $this->request->query('class_id') : -1;
        $educationGradeByReportCardId = '';//POCOR-7212
        if ($selectedReportCard != -1) {
            $reportCardEntity = $this->ReportCards->find()->where(['id' => $selectedReportCard])->first();
            if (!empty($reportCardEntity)) {
                $classOptions = $Classes->find('list')
                    ->matching('ClassGrades')
                    ->where([
                        $Classes->aliasField('academic_period_id') => $selectedAcademicPeriod,
                        $Classes->aliasField('institution_id') => $institutionId,
                        'ClassGrades.education_grade_id' => $reportCardEntity->education_grade_id
                    ])
                    ->order([$Classes->aliasField('name')])
                    ->toArray();
                $educationGradeByReportCardId = $reportCardEntity->education_grade_id;//POCOR-7212
            } else {
                // if selected report card is not valid, do not show any students
                $selectedClass = -1;
            }
        }

        if (!empty($classOptions)) {
            $classOptions['all'] = "All Classes";
        }

        $classOptions = ['-1' => '-- ' . __('Select Class') . ' --'] + $classOptions;
        $this->controller->set(compact('classOptions', 'selectedClass'));
        $where[$this->aliasField('institution_class_id')] = $selectedClass;
        $where[$this->aliasField('institution_id')] = $institutionId; //POCOR-6817
        $where[$this->aliasField('student_status_id NOT IN')] = 3; //POCOR-6817
        //POCOR-7212 starts
        if (!empty($educationGradeByReportCardId)) {
            $where[$this->aliasField('education_grade_id')] = $educationGradeByReportCardId;
        }//POCOR-7212 ends
        //End

        $query
            ->select([
                'report_card_id' => $this->StudentsReportCards->aliasField('report_card_id'),
                'report_card_status' => $this->StudentsReportCards->aliasField('status'),
                'report_card_started_on' => $this->StudentsReportCards->aliasField('started_on'),
                'report_card_completed_on' => $this->StudentsReportCards->aliasField('completed_on'),
                'email_status_id' => $this->ReportCardEmailProcesses->aliasField('status'),
                'email_error_message' => $this->ReportCardEmailProcesses->aliasField('error_message')
            ])
            //POCOR-7153[START]
            ->matching('StudentStatuses', function ($q) {
                return $q->where(['StudentStatuses.code NOT IN ' => ['WITHDRAWN']]);
            })
            //POCOR-7153[END]
            ->leftJoin([$this->StudentsReportCards->alias() => $this->StudentsReportCards->table()],
                [
                    $this->StudentsReportCards->aliasField('student_id = ') . $this->aliasField('student_id'),
                    $this->StudentsReportCards->aliasField('institution_id = ') . $this->aliasField('institution_id'),
                    $this->StudentsReportCards->aliasField('academic_period_id = ') . $this->aliasField('academic_period_id'),
                    $this->StudentsReportCards->aliasField('education_grade_id = ') . $this->aliasField('education_grade_id'),
                    $this->StudentsReportCards->aliasField('institution_class_id = ') . $this->aliasField('institution_class_id'),
                    $this->StudentsReportCards->aliasField('report_card_id = ') . $selectedReportCard
                ]
            )
            ->leftJoin([$this->ReportCardEmailProcesses->alias() => $this->ReportCardEmailProcesses->table()],
                [
                    $this->ReportCardEmailProcesses->aliasField('student_id = ') . $this->aliasField('student_id'),
                    $this->ReportCardEmailProcesses->aliasField('institution_id = ') . $this->aliasField('institution_id'),
                    $this->ReportCardEmailProcesses->aliasField('academic_period_id = ') . $this->aliasField('academic_period_id'),
                    $this->ReportCardEmailProcesses->aliasField('education_grade_id = ') . $this->aliasField('education_grade_id'),
                    $this->ReportCardEmailProcesses->aliasField('institution_class_id = ') . $this->aliasField('institution_class_id'),
                    $this->ReportCardEmailProcesses->aliasField('report_card_id = ') . $selectedReportCard
                ]
            )
            ->autoFields(true)
            ->where($where)
            ->all();

        if (is_null($this->request->query('sort'))) {
            $query
                ->contain('Users')
                ->order(['Users.first_name', 'Users.last_name']);
        }

        $extra['elements']['controls'] = ['name' => 'Institution.ReportCards/controls', 'data' => [], 'options' => [], 'order' => 1];

        // sort
        $sortList = ['report_card_status', 'Users.first_name', 'Users.openemis_no'];
        if (array_key_exists('sortWhitelist', $extra['options'])) {
            $sortList = array_merge($extra['options']['sortWhitelist'], $sortList);
        }
        $extra['options']['sortWhitelist'] = $sortList;

        // search
        $search = $this->getSearchKey();
        if (!empty($search)) {
            $nameConditions = $this->getNameSearchConditions(['alias' => 'Users', 'searchTerm' => $search]);
            $extra['OR'] = $nameConditions; // to be merged with auto_search 'OR' conditions
        }
    }

    public function indexAfterAction(Event $event, Query $query, ResultSet $data, ArrayObject $extra)
    {
        $reportCardId = $this->request->query('report_card_id');
        $classId = $this->request->query('class_id');
        //POCOR-7131 starts
        $loginUserIdUser = $this->Auth->User('id');
        $securityRoles = $this->AccessControl->getRolesByUser($loginUserIdUser)->toArray();
        $securityRoleIds = [];
        foreach ($securityRoles as $key => $value) {
            $securityRoleIds[] = $value->security_role_id;
        }//POCOR-7131 ends
        $userSuperAddmin = $this->Session->read('Auth.User.super_admin'); //POCOR-7163 :: Start
        if ($userSuperAddmin == 1) {
            if (!is_null($reportCardId) && !is_null($classId)) {
                $existingReportCard = $this->ReportCards->exists([$this->ReportCards->primaryKey() => $reportCardId]);
                $existingClass = $this->InstitutionClasses->exists([$this->InstitutionClasses->primaryKey() => $classId]);
                // only show toolbar buttons if request for report card and class is valid
                if ($existingReportCard && $existingClass) {
                    $generatedCount = 0;
                    $publishedCount = 0;
                    $dataCount = count($data);//POCOR-8300
                    // count statuses to determine which buttons are shown
                    foreach ($data as $student) {
                        if ($student->has('report_card_status')) {
                            if ($student->report_card_status == self::GENERATED) {
                                $generatedCount += 1;
                            } else if ($student->report_card_status == self::PUBLISHED) {
                                $publishedCount += 1;
                            }
                        }
                    }

                    $toolbarAttr = [
                        'class' => 'btn btn-xs btn-default',
                        'data-toggle' => 'tooltip',
                        'data-placement' => 'bottom',
                        'escape' => false
                    ];

                    $params = [
                        'institution_id' => $this->Session->read('Institution.Institutions.id'),
                        'institution_class_id' => $classId,
                        'report_card_id' => $reportCardId
                    ];

                    $SecurityFunctions = TableRegistry::get('Security.SecurityFunctions');
                    $SecurityFunctionsAllExcelData = $SecurityFunctions
                        ->find()
                        ->where([
                            $SecurityFunctions->aliasField('name') => 'Download All Excel'])
                        ->first();

                    $SecurityRoleFunctionsTable = TableRegistry::get('Security.SecurityRoleFunctions');
                    $SecurityRoleFunctionsTableAllExcelData = $SecurityRoleFunctionsTable
                        ->find()
                        ->where([
                            $SecurityRoleFunctionsTable->aliasField('security_function_id') => $SecurityFunctionsAllExcelData->id,


                        ])
                        ->count();

                    $SecurityFunctions = TableRegistry::get('Security.SecurityFunctions');
                    $SecurityFunctionsAllPdfData = $SecurityFunctions
                        ->find()
                        ->where([
                            $SecurityFunctions->aliasField('name') => 'Download All Pdf'])
                        ->first();

                    $SecurityRoleFunctionsTable = TableRegistry::get('Security.SecurityRoleFunctions');
                    $SecurityRoleFunctionsTableAllPdfData = $SecurityRoleFunctionsTable
                        ->find()
                        ->where([
                            $SecurityRoleFunctionsTable->aliasField('security_function_id') => $SecurityFunctionsAllPdfData->id,
                            //$SecurityRoleFunctionsTable->aliasField('_execute') => 1
                        ])
                        ->count();

                    $SecurityFunctions = TableRegistry::get('Security.SecurityFunctions');
                    $SecurityFunctionsGenerateAllData = $SecurityFunctions
                        ->find()
                        ->where([
                            $SecurityFunctions->aliasField('name') => 'Generate All'])
                        ->first();

                    $SecurityRoleFunctionsTable = TableRegistry::get('Security.SecurityRoleFunctions');
                    $SecurityRoleFunctionsTableGenerateAllData = $SecurityRoleFunctionsTable
                        ->find()
                        ->where([
                            $SecurityRoleFunctionsTable->aliasField('security_function_id') => $SecurityFunctionsGenerateAllData->id,
                            //$SecurityRoleFunctionsTable->aliasField('_execute') => 1,/
                        ])
                        ->count();
                    // Start POCOR-7320
                    $SecurityFunctionsMergeGenerateAllData = $SecurityFunctions
                        ->find()
                        ->where([
                            $SecurityFunctions->aliasField('name') => 'Merge and Download PDF'])
                        ->first();

                    $SecurityRoleFunctionsTableMergeGenerateAllData = $SecurityRoleFunctionsTable
                        ->find()
                        ->where([
                            $SecurityRoleFunctionsTable->aliasField('security_function_id') => $SecurityFunctionsMergeGenerateAllData->id,
                            //$SecurityRoleFunctionsTable->aliasField('_execute') => 1,/
                        ])
                        ->count();

                    if (($dataCount == $generatedCount) && ($generatedCount > 0 || $publishedCount > 0)) { //POCOR-8300
                        if ($this->AccessControl->isAdmin()) {
                            $downloadButtonPdf['url'] = $this->setQueryString($this->url('mergeAnddownloadAllPdf'), $params);
                            $downloadButtonPdf['type'] = 'button';
                            $downloadButtonPdf['label'] = '<i class="fa kd-download"></i>';
                            $downloadButtonPdf['attr'] = $toolbarAttr;
                            $downloadButtonPdf['attr']['title'] = __('Merge and Download PDF');
                            $downloadButtonPdf['attr']['target'] = '_blank';
                            $extra['toolbarButtons']['mergeAnddownloadAllPdf'] = $downloadButtonPdf;
                        } else {
                            if ($SecurityRoleFunctionsTableMergeGenerateAllData >= 1) {
                                $downloadButtonPdf['url'] = $this->setQueryString($this->url('mergeAnddownloadAllPdf'), $params);
                                $downloadButtonPdf['type'] = 'button';
                                $downloadButtonPdf['label'] = '<i class="fa kd-download"></i>';
                                $downloadButtonPdf['attr'] = $toolbarAttr;
                                $downloadButtonPdf['attr']['title'] = __('Merge and Download PDF');
                                $downloadButtonPdf['attr']['target'] = '_blank';
                                $extra['toolbarButtons']['mergeAnddownloadAllPdf'] = $downloadButtonPdf;
                            }
                        }
                    }
                    // End POCOR-7320
                    // Download all button
                    if ($generatedCount > 0 || $publishedCount > 0) {
                        if ($this->AccessControl->isAdmin()) {
                            $downloadButtonPdf['url'] = $this->setQueryString($this->url('downloadAllPdf'), $params);
                            $downloadButtonPdf['type'] = 'button';
                            $downloadButtonPdf['label'] = '<i class="fa kd-download"></i>';
                            $downloadButtonPdf['attr'] = $toolbarAttr;
                            $downloadButtonPdf['attr']['title'] = __('Download All PDF');
                            $extra['toolbarButtons']['downloadAllPdf'] = $downloadButtonPdf;
                        } else {
                            if ($SecurityRoleFunctionsTableAllPdfData >= 1) {
                                $downloadButtonPdf['url'] = $this->setQueryString($this->url('downloadAllPdf'), $params);
                                $downloadButtonPdf['type'] = 'button';
                                $downloadButtonPdf['label'] = '<i class="fa kd-download"></i>';
                                $downloadButtonPdf['attr'] = $toolbarAttr;
                                $downloadButtonPdf['attr']['title'] = __('Download All PDF');
                                $extra['toolbarButtons']['downloadAllPdf'] = $downloadButtonPdf;
                            }
                        }
                    }
                    if ($generatedCount > 0 || $publishedCount > 0) {
                        if ($this->AccessControl->isAdmin()) {
                            $downloadButton['url'] = $this->setQueryString($this->url('downloadAll'), $params);
                            $downloadButton['type'] = 'button';
                            $downloadButton['label'] = '<i class="fa kd-download"></i>';
                            $downloadButton['attr'] = $toolbarAttr;
                            $downloadButton['attr']['title'] = __('Download All Excel');
                            $extra['toolbarButtons']['downloadAll'] = $downloadButton;
                        } else {
                            //POCOR-7656 start
                            $ExcludedSecurityRoleEntity = $this->canGenerateAnyDate($reportCardId);  //POCOR-7551
                            //POCOR-7656 end
                            if (($SecurityRoleFunctionsTableAllExcelData >= 1) || ($ExcludedSecurityRoleEntity == 1)) {
                                $downloadButton['url'] = $this->setQueryString($this->url('downloadAll'), $params);
                                $downloadButton['type'] = 'button';
                                $downloadButton['label'] = '<i class="fa kd-download"></i>';
                                $downloadButton['attr'] = $toolbarAttr;
                                $downloadButton['attr']['title'] = __('Download All Excel');
                                $extra['toolbarButtons']['downloadAll'] = $downloadButton;
                            }
                        }
                    }
                    // Generate all button
                    $generateButton['url'] = $this->setQueryString($this->url('generateAll'), $params);
                    $generateButton['type'] = 'button';
                    $generateButton['label'] = '<i class="fa fa-refresh"></i>';
                    $generateButton['attr'] = $toolbarAttr;
                    $generateButton['attr']['title'] = __('Generate All');
                    //$ReportCards = TableRegistry::get('ReportCard.ReportCards');
                    if (!is_null($this->request->query('report_card_id'))) {
                        $reportCardId = $this->request->query('report_card_id');
                    }

                    $ReportCardsData = $this->ReportCards
                        ->find()
                        ->where([
                            $this->ReportCards->aliasField('id') => $reportCardId])
                        ->first();

                    if (!empty($ReportCardsData->generate_start_date)) {
                        $generateStartDate = $ReportCardsData->generate_start_date->format('Y-m-d');
                    }

                    if (!empty($ReportCardsData->generate_end_date)) {
                        $generateEndDate = $ReportCardsData->generate_end_date->format('Y-m-d');
                    }
                    $date = Time::now()->format('Y-m-d');

                    if ($this->AccessControl->isAdmin()) {
                        // if (!empty($generateStartDate) && !empty($generateEndDate) && $date >= $generateStartDate && $date <= $generateEndDate) {
                        // This condition is removed for allowing admin to generate report cards even if it is not within generate start and end date
                        if (!empty($generateStartDate) && !empty($generateEndDate)) {//POCOR-7761
                            $extra['toolbarButtons']['generateAll'] = $generateButton;
                        } else {
                            $generateButton['attr']['data-html'] = true;
                            $generateButton['attr']['title'] .= __('<br>' . $this->getMessage('ReportCardStatuses.date_closed'));
                            $generateButton['url'] = 'javascript:void(0)';
                            $extra['toolbarButtons']['generateAll'] = $generateButton;
                        }
                    } else {
                        if ($SecurityRoleFunctionsTableGenerateAllData >= 1) {
                            if (!empty($generateStartDate) && !empty($generateEndDate) && $date >= $generateStartDate && $date <= $generateEndDate) {
                                $extra['toolbarButtons']['generateAll'] = $generateButton;
                            } else {
                                $generateButton['attr']['data-html'] = true;
                                $generateButton['attr']['title'] .= __('<br>' . $this->getMessage('ReportCardStatuses.date_closed'));
                                $generateButton['url'] = 'javascript:void(0)';
                                $extra['toolbarButtons']['generateAll'] = $generateButton;
                            }
                        }
                    }

                    // Publish all button
                    if ($generatedCount > 0) {
                        $publishButton['url'] = $this->setQueryString($this->url('publishAll'), $params);
                        $publishButton['type'] = 'button';
                        $publishButton['label'] = '<i class="fa kd-publish"></i>';
                        $publishButton['attr'] = $toolbarAttr;
                        $publishButton['attr']['title'] = __('Publish All');
                        $extra['toolbarButtons']['publishAll'] = $publishButton;
                    }
                    // Unpublish all button
                    if ($publishedCount > 0) {
                        $unpublishButton['url'] = $this->setQueryString($this->url('unpublishAll'), $params);
                        $unpublishButton['type'] = 'button';
                        $unpublishButton['label'] = '<i class="fa kd-unpublish"></i>';
                        $unpublishButton['attr'] = $toolbarAttr;
                        $unpublishButton['attr']['title'] = __('Unpublish All');
                        $extra['toolbarButtons']['unpublishAll'] = $unpublishButton;
                    }
                    // Email all pdf button is published
                    if ($publishedCount > 0) {
                        $emailButton['url'] = $this->setQueryString($this->url('emailAllPdf'), $params);
                        $emailButton['type'] = 'button';
                        $emailButton['label'] = '<i class="fa fa-envelope"></i>';
                        $emailButton['attr'] = $toolbarAttr;
                        $emailButton['attr']['title'] = __('Email All PDF');
                        $extra['toolbarButtons']['emailAllPdf'] = $emailButton;
                    }
                    // Email all excel button is published
                    if ($publishedCount > 0) {
                        $emailExcelButton['url'] = $this->setQueryString($this->url('emailAllExcel'), $params);
                        $emailExcelButton['type'] = 'button';
                        $emailExcelButton['label'] = '<i class="fa fa-envelope"></i>';
                        $emailExcelButton['attr'] = $toolbarAttr;
                        $emailExcelButton['attr']['title'] = __('Email All Excel');
                        $extra['toolbarButtons']['emailAllExcel'] = $emailExcelButton;
                    }
                }
            }
        } else { //POCOR-7163 :: End here and condition same for other users
            if (!is_null($reportCardId) && !is_null($classId) && !empty($securityRoleIds)) { //POCOR-7148 check empty condition for securityRoleIds
                $existingReportCard = $this->ReportCards->exists([$this->ReportCards->primaryKey() => $reportCardId]);
                $existingClass = $this->InstitutionClasses->exists([$this->InstitutionClasses->primaryKey() => $classId]);
                // only show toolbar buttons if request for report card and class is valid
                if ($existingReportCard && $existingClass) {
                    $generatedCount = 0;
                    $publishedCount = 0;
                    $dataCount = count($data);//POCOR-8300
                    // count statuses to determine which buttons are shown
                    foreach ($data as $student) {
                        if ($student->has('report_card_status')) {
                            if ($student->report_card_status == self::GENERATED) {
                                $generatedCount += 1;
                            } else if ($student->report_card_status == self::PUBLISHED) {
                                $publishedCount += 1;
                            }
                        }
                    }

                    $toolbarAttr = [
                        'class' => 'btn btn-xs btn-default',
                        'data-toggle' => 'tooltip',
                        'data-placement' => 'bottom',
                        'escape' => false
                    ];

                    $params = [
                        'institution_id' => $this->Session->read('Institution.Institutions.id'),
                        'institution_class_id' => $classId,
                        'report_card_id' => $reportCardId
                    ];

                    //POCOR-6838: Start
                    $SecurityFunctions = TableRegistry::get('Security.SecurityFunctions');
                    $SecurityFunctionsAllExcelData = $SecurityFunctions
                        ->find()
                        ->where([
                            $SecurityFunctions->aliasField('name') => 'Download All Excel'])
                        ->first();

                    $SecurityRoleFunctionsTable = TableRegistry::get('Security.SecurityRoleFunctions');
                    $SecurityRoleFunctionsTableAllExcelData = $SecurityRoleFunctionsTable
                        ->find()
                        ->where([
                            $SecurityRoleFunctionsTable->aliasField('security_function_id') => $SecurityFunctionsAllExcelData->id,
                            $SecurityRoleFunctionsTable->aliasField('_execute') => 1,//POCOR-7131
                            $SecurityRoleFunctionsTable->aliasField('security_role_id IN') => $securityRoleIds//POCOR-7131
                        ])
                        ->count();//POCOR-7131

                    $SecurityFunctions = TableRegistry::get('Security.SecurityFunctions');
                    $SecurityFunctionsAllPdfData = $SecurityFunctions
                        ->find()
                        ->where([
                            $SecurityFunctions->aliasField('name') => 'Download All Pdf'])
                        ->first();

                    $SecurityRoleFunctionsTable = TableRegistry::get('Security.SecurityRoleFunctions');
                    $SecurityRoleFunctionsTableAllPdfData = $SecurityRoleFunctionsTable
                        ->find()
                        ->where([
                            $SecurityRoleFunctionsTable->aliasField('security_function_id') => $SecurityFunctionsAllPdfData->id,
                            $SecurityRoleFunctionsTable->aliasField('_execute') => 1,//POCOR-7131
                            $SecurityRoleFunctionsTable->aliasField('security_role_id IN') => $securityRoleIds])//POCOR-7131
                        ->count();//POCOR-7131

                    $SecurityFunctions = TableRegistry::get('Security.SecurityFunctions');
                    $SecurityFunctionsGenerateAllData = $SecurityFunctions
                        ->find()
                        ->where([
                            $SecurityFunctions->aliasField('name') => 'Generate All'])
                        ->first();

                    $SecurityRoleFunctionsTable = TableRegistry::get('Security.SecurityRoleFunctions');
                    $SecurityRoleFunctionsTableGenerateAllData = $SecurityRoleFunctionsTable
                        ->find()
                        ->where([
                            $SecurityRoleFunctionsTable->aliasField('security_function_id') => $SecurityFunctionsGenerateAllData->id,
                            $SecurityRoleFunctionsTable->aliasField('_execute') => 1,//POCOR-7131
                            $SecurityRoleFunctionsTable->aliasField('security_role_id IN') => $securityRoleIds])//POCOR-7131
                        ->count();//POCOR-7131
                    //POCOR-6838: End
                    // Start POCOR-7320
                    $SecurityFunctionsMergeGenerateAllData = $SecurityFunctions
                        ->find()
                        ->where([
                            $SecurityFunctions->aliasField('name') => 'Merge and Download PDF'])
                        ->first();

                    $SecurityRoleFunctionsTableMergeGenerateAllData = $SecurityRoleFunctionsTable
                        ->find()
                        ->where([
                            $SecurityRoleFunctionsTable->aliasField('security_function_id') => $SecurityFunctionsMergeGenerateAllData->id,
                            //$SecurityRoleFunctionsTable->aliasField('_execute') => 1,/
                        ])
                        ->count();
                    if (($dataCount == $generatedCount) && ($generatedCount > 0 || $publishedCount > 0)) {
                        if ($this->AccessControl->isAdmin()) {
                            $downloadButtonPdf['url'] = $this->setQueryString($this->url('mergeAnddownloadAllPdf'), $params);
                            $downloadButtonPdf['type'] = 'button';
                            $downloadButtonPdf['label'] = '<i class="fa kd-download"></i>';
                            $downloadButtonPdf['attr'] = $toolbarAttr;
                            $downloadButtonPdf['attr']['title'] = __('Merge and Download PDF');
                            $downloadButtonPdf['attr']['target'] = '_blank';
                            $extra['toolbarButtons']['mergeAnddownloadAllPdf'] = $downloadButtonPdf;
                        } else {
                            if ($SecurityRoleFunctionsTableMergeGenerateAllData >= 1) {
                                $downloadButtonPdf['url'] = $this->setQueryString($this->url('mergeAnddownloadAllPdf'), $params);
                                $downloadButtonPdf['type'] = 'button';
                                $downloadButtonPdf['label'] = '<i class="fa kd-download"></i>';
                                $downloadButtonPdf['attr'] = $toolbarAttr;
                                $downloadButtonPdf['attr']['title'] = __('Merge and Download PDF');
                                $downloadButtonPdf['attr']['target'] = '_blank';
                                $extra['toolbarButtons']['mergeAnddownloadAllPdf'] = $downloadButtonPdf;
                            }
                        }
                    }

                    // End POCOR-7320
                    // Download all button
                    if ($generatedCount > 0 || $publishedCount > 0) {
                        if ($this->AccessControl->isAdmin()) {
                            $downloadButtonPdf['url'] = $this->setQueryString($this->url('downloadAllPdf'), $params);
                            $downloadButtonPdf['type'] = 'button';
                            $downloadButtonPdf['label'] = '<i class="fa kd-download"></i>';
                            $downloadButtonPdf['attr'] = $toolbarAttr;
                            $downloadButtonPdf['attr']['title'] = __('Download All PDF');
                            $extra['toolbarButtons']['downloadAllPdf'] = $downloadButtonPdf;
                        } else {
                            if ($SecurityRoleFunctionsTableAllPdfData >= 1) {//POCOR-7131 change in if condition
                                $downloadButtonPdf['url'] = $this->setQueryString($this->url('downloadAllPdf'), $params);
                                $downloadButtonPdf['type'] = 'button';
                                $downloadButtonPdf['label'] = '<i class="fa kd-download"></i>';
                                $downloadButtonPdf['attr'] = $toolbarAttr;
                                $downloadButtonPdf['attr']['title'] = __('Download All PDF');
                                $extra['toolbarButtons']['downloadAllPdf'] = $downloadButtonPdf;
                            }
                        }
                    }
                    if ($generatedCount > 0 || $publishedCount > 0) {
                        if ($this->AccessControl->isAdmin()) {
                            $downloadButton['url'] = $this->setQueryString($this->url('downloadAll'), $params);
                            $downloadButton['type'] = 'button';
                            $downloadButton['label'] = '<i class="fa kd-download"></i>';
                            $downloadButton['attr'] = $toolbarAttr;
                            $downloadButton['attr']['title'] = __('Download All Excel');
                            $extra['toolbarButtons']['downloadAll'] = $downloadButton;
                        } else {
                            if ($SecurityRoleFunctionsTableAllExcelData >= 1) {//POCOR-7131 change in if condition
                                $downloadButton['url'] = $this->setQueryString($this->url('downloadAll'), $params);
                                $downloadButton['type'] = 'button';
                                $downloadButton['label'] = '<i class="fa kd-download"></i>';
                                $downloadButton['attr'] = $toolbarAttr;
                                $downloadButton['attr']['title'] = __('Download All Excel');
                                $extra['toolbarButtons']['downloadAll'] = $downloadButton;
                            }
                        }
                    }

                    // Generate all button
                    $generateButton['url'] = $this->setQueryString($this->url('generateAll'), $params);
                    $generateButton['type'] = 'button';
                    $generateButton['label'] = '<i class="fa fa-refresh"></i>';
                    $generateButton['attr'] = $toolbarAttr;
                    $generateButton['attr']['title'] = __('Generate All');
                    //$ReportCards = TableRegistry::get('ReportCard.ReportCards');
                    if (!is_null($this->request->query('report_card_id'))) {
                        $reportCardId = $this->request->query('report_card_id');
                    }

                    $ReportCardsData = $this->ReportCards
                        ->find()
                        ->where([
                            $this->ReportCards->aliasField('id') => $reportCardId])
                        ->first();
                    if (!empty($ReportCardsData->generate_start_date)) {
                        $generateStartDate = $ReportCardsData->generate_start_date->format('Y-m-d');
                    }

                    if (!empty($ReportCardsData->generate_end_date)) {
                        $generateEndDate = $ReportCardsData->generate_end_date->format('Y-m-d');
                    }
                    $date = Time::now()->format('Y-m-d');

                    if ($this->AccessControl->isAdmin()) {
                        if (!empty($generateStartDate) && !empty($generateEndDate) && $date >= $generateStartDate && $date <= $generateEndDate) {
                            $extra['toolbarButtons']['generateAll'] = $generateButton;
                        } else {
                            $generateButton['attr']['data-html'] = true;
                            $generateButton['attr']['title'] .= __('<br>' . $this->getMessage('ReportCardStatuses.date_closed'));
                            $generateButton['url'] = 'javascript:void(0)';
                            $extra['toolbarButtons']['generateAll'] = $generateButton;
                        }
                    } else {
                        //POCOR-7656 start
                        $ExcludedSecurityRoleEntity = $this->canGenerateAnyDate($reportCardId);  //POCOR-7551
                        //POCOR-7656 end
                        if ($SecurityRoleFunctionsTableGenerateAllData >= 1) {//POCOR-7131 change in if condition
                            if ((!empty($generateStartDate) && !empty($generateEndDate) && $date >= $generateStartDate && $date <= $generateEndDate) || ($ExcludedSecurityRoleEntity == 1)) {
                                $extra['toolbarButtons']['generateAll'] = $generateButton;
                            } else {
                                $generateButton['attr']['data-html'] = true;
                                $generateButton['attr']['title'] .= __('<br>' . $this->getMessage('ReportCardStatuses.date_closed'));
                                $generateButton['url'] = 'javascript:void(0)';
                                $extra['toolbarButtons']['generateAll'] = $generateButton;
                            }
                        }
                    }

                    // Publish all button
                    if ($generatedCount > 0) {
                        $publishButton['url'] = $this->setQueryString($this->url('publishAll'), $params);
                        $publishButton['type'] = 'button';
                        $publishButton['label'] = '<i class="fa kd-publish"></i>';
                        $publishButton['attr'] = $toolbarAttr;
                        $publishButton['attr']['title'] = __('Publish All');
                        $extra['toolbarButtons']['publishAll'] = $publishButton;
                    }
                    // Unpublish all button
                    if ($publishedCount > 0) {
                        $unpublishButton['url'] = $this->setQueryString($this->url('unpublishAll'), $params);
                        $unpublishButton['type'] = 'button';
                        $unpublishButton['label'] = '<i class="fa kd-unpublish"></i>';
                        $unpublishButton['attr'] = $toolbarAttr;
                        $unpublishButton['attr']['title'] = __('Unpublish All');
                        $extra['toolbarButtons']['unpublishAll'] = $unpublishButton;
                    }
                    // Email all pdf button is published
                    if ($publishedCount > 0) {
                        $emailButton['url'] = $this->setQueryString($this->url('emailAllPdf'), $params);
                        $emailButton['type'] = 'button';
                        $emailButton['label'] = '<i class="fa fa-envelope"></i>';
                        $emailButton['attr'] = $toolbarAttr;
                        $emailButton['attr']['title'] = __('Email All PDF');
                        $extra['toolbarButtons']['emailAllPdf'] = $emailButton;
                    }
                    // Email all excel button is published
                    if ($publishedCount > 0) {
                        $emailExcelButton['url'] = $this->setQueryString($this->url('emailAllExcel'), $params);
                        $emailExcelButton['type'] = 'button';
                        $emailExcelButton['label'] = '<i class="fa fa-envelope"></i>';
                        $emailExcelButton['attr'] = $toolbarAttr;
                        $emailExcelButton['attr']['title'] = __('Email All Excel');
                        $extra['toolbarButtons']['emailAllExcel'] = $emailExcelButton;
                    }
                }
            }
        }
    }

    // Start POCOR-7320

    public function mergeAnddownloadAllPdf(Event $event, ArrayObject $extra)
    {
        // ini_set('max_execution_time', '1500');
        $params = $this->getQueryString();
        $statusArray = [self::GENERATED, self::PUBLISHED];
        $files = $this->StudentsReportCards->find()
            ->contain(['Students', 'ReportCards'])
            ->where([
                $this->StudentsReportCards->aliasField('institution_id') => $params['institution_id'],
                $this->StudentsReportCards->aliasField('institution_class_id') => $params['institution_class_id'],
                $this->StudentsReportCards->aliasField('report_card_id') => $params['report_card_id'],
                $this->StudentsReportCards->aliasField('status IN ') => $statusArray,
                $this->StudentsReportCards->aliasField('file_name IS NOT NULL'),
                $this->StudentsReportCards->aliasField('file_content IS NOT NULL'),
                $this->StudentsReportCards->aliasField('file_content_pdf IS NOT NULL')
            ])
            ->toArray();

        if (!empty($files)) {
            header('Content-type: application/pdf');
            header('Content-Disposition: inline; filename="' . $fileName . '"');
            header('Content-Transfer-Encoding: binary');
            header('Accept-Ranges: bytes');
            $filePaths = [];

            $path = WWW_ROOT . 'export' . DS . 'customexcel' . DS;
            $counter = 0;
            foreach ($files as $file) {
                $filename = 'ReportCards' . '_' . date('Ymd') . '_' . $counter . '.pdf';
                $filepath = $path . $filename;
                file_put_contents($filepath, $this->getFile($file->file_content_pdf));
                $filePaths[] = $path . $filename;
                $counter++;
            }
            if (!empty($filePaths)) {
                $this->mergePDFFiles($filePaths);
            }

        } else {
            $event->stopPropagation();
            $this->Alert->warning('ReportCardStatuses.noFilesToDownload');
            return $this->controller->redirect($this->url('index'));
        }
    }


    private function mergePDFFiles(Array $filenames, $outFile = '', $title = '', $author = '', $subject = '')
    {
        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => [400, 220]]);
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor($author);
        $mpdf->SetSubject($subject);


        if ($filenames) {
            $filesTotal = sizeof($filenames);
            $mpdf->SetImportUse();

            for ($i = 0; $i < count($filenames); $i++) {
                $curFile = $filenames[$i];
                if (file_exists($curFile)) {
                    $pageCount = $mpdf->SetSourceFile($curFile);
                    for ($p = 1; $p <= $pageCount; $p++) {
                        $tplId = $mpdf->ImportPage($p);
                        $wh = $mpdf->getTemplateSize($tplId);
                        if (($p == 1)) {
                            $mpdf->state = 0;
                            $mpdf->AddPage('L');

                            $mpdf->UseTemplate($tplId);
                        } else {
                            $mpdf->state = 1;
                            $mpdf->AddPage('L');

                            $mpdf->UseTemplate($tplId);
                        }
                    }
                }
            }
            foreach ($filenames as $filepath) {
                unlink($filepath);
            }
        }

        $mpdf->Output('mergedPDFReport.pdf', "D");
    }

    // End POCOR-7320
    public function getSearchableFields(Event $event, ArrayObject $searchableFields)
    {
        $searchableFields[] = 'student_id';
        $searchableFields[] = 'openemis_no';
    }

    public function viewBeforeAction(Event $event, ArrayObject $extra)
    {
        $this->field('institution_class_id', ['type' => 'integer']);
        $this->field('academic_period_id', ['visible' => true]);
        $this->field('gpa', ['visible' => true]);
        $this->setFieldOrder(['academic_period_id', 'institution_class_id', 'openemis_no', 'student_id', 'gpa', 'report_card', 'status', 'started_on', 'completed_on', 'report_queue', 'email_status']);
    }

    public function viewBeforeQuery(Event $event, Query $query, ArrayObject $extra)
    {
        $params = $this->request->query;
        $reportCardTable = TableRegistry::get('report_cards');
        //POCOR-7605 start
        $decodeParam = $this->paramsDecode($this->request->params['pass'][1]);
        $conditions = [];
        $CheckStudent = $this->StudentsReportCards->find()->where([$this->StudentsReportCards->aliasField('student_id') => $decodeParam['student_id'], $this->StudentsReportCards->aliasField('report_card_id') => $params['report_card_id']])->first();
        if (!empty($CheckStudent)) {
            $conditions[$this->StudentsReportCards->aliasField('report_card_id')] = $params['report_card_id'];

        }
        //POCOR-7605 end
        $query
            ->select([
                'report_card_id' => $this->StudentsReportCards->aliasField('report_card_id'),
                'report_card_status' => $this->StudentsReportCards->aliasField('status'),
                'report_card_started_on' => $this->StudentsReportCards->aliasField('started_on'),
                'report_card_completed_on' => $this->StudentsReportCards->aliasField('completed_on'),
                'email_status_id' => $this->ReportCardEmailProcesses->aliasField('status'),
                'email_error_message' => $this->ReportCardEmailProcesses->aliasField('error_message'),
                'gpa' => $this->StudentsReportCards->aliasField('gpa'),//POCOR-7318
            ])
            ->leftJoin([$this->StudentsReportCards->alias() => $this->StudentsReportCards->table()],
                [
                    $this->StudentsReportCards->aliasField('student_id = ') . $this->aliasField('student_id'),
                    $this->StudentsReportCards->aliasField('institution_id = ') . $this->aliasField('institution_id'),
                    $this->StudentsReportCards->aliasField('academic_period_id = ') . $this->aliasField('academic_period_id'),
                    $this->StudentsReportCards->aliasField('education_grade_id = ') . $this->aliasField('education_grade_id'),
                    $this->StudentsReportCards->aliasField('institution_class_id = ') . $this->aliasField('institution_class_id')
                ]
            )
            ->leftJoin([$this->ReportCardEmailProcesses->alias() => $this->ReportCardEmailProcesses->table()],
                [
                    $this->ReportCardEmailProcesses->aliasField('student_id = ') . $this->aliasField('student_id'),
                    $this->ReportCardEmailProcesses->aliasField('institution_id = ') . $this->aliasField('institution_id'),
                    $this->ReportCardEmailProcesses->aliasField('academic_period_id = ') . $this->aliasField('academic_period_id'),
                    $this->ReportCardEmailProcesses->aliasField('education_grade_id = ') . $this->aliasField('education_grade_id'),
                    $this->ReportCardEmailProcesses->aliasField('institution_class_id = ') . $this->aliasField('institution_class_id'),
                    $this->ReportCardEmailProcesses->aliasField('report_card_id = ') . $params['report_card_id']
                ]
            )
            ->where($conditions)
            ->order(['report_card_id' => 'DESC'])
            ->autoFields(true);


    }

    public function onGetStatus(Event $event, Entity $entity)
    {
        if ($entity->has('report_card_status')) {
            $value = $this->statusOptions[$entity->report_card_status];
        } else {
            $value = $this->statusOptions[self::NEW_REPORT];
        }
        return $value;
    }

    public function onGetStartedOn(Event $event, Entity $entity)
    {
        //START: POCOR-6716
        // if ($entity->has('report_card_started_on')) {
        //     $startedOnValue = new Time($entity->report_card_started_on);
        //     $value = $this->formatDateTime($startedOnValue);
        // }
        $ConfigItemTable = TableRegistry::get('Configuration.ConfigItems');
        $ConfigItem = $ConfigItemTable
            ->find()
            ->select(['zonevalue' => 'ConfigItems.value'])
            ->where([
                $ConfigItemTable->aliasField('name') => 'Time Zone'
            ])
            ->first();
        $timZone = $ConfigItem->zonevalue;
        $value = '';
        if ($timZone) {//POCOR-7581
            if ($entity->has('report_card_started_on')) {
                $date = new DateTime($entity->report_card_started_on, new DateTimeZone($timZone));
                $date->setTimezone(new DateTimeZone($timZone));
                $value = $date->format('F d, Y h:i:s');
            }
        }//POCOR-7581
        return $value;
        //END: POCOR-6716
    }

    public function onGetCompletedOn(Event $event, Entity $entity)
    {
        //START: POCOR-6716
        // if ($entity->has('report_card_completed_on')) {
        //     $completedOnValue = new Time($entity->report_card_completed_on);
        //     $value = $this->formatDateTime($completedOnValue);
        // }
        $ConfigItemTable = TableRegistry::get('Configuration.ConfigItems');
        $ConfigItem = $ConfigItemTable
            ->find()
            ->select(['zonevalue' => 'ConfigItems.value'])
            ->where([
                $ConfigItemTable->aliasField('name') => 'Time Zone'
            ])
            ->first();
        $timZone = $ConfigItem->zonevalue;
        $value = '';
        if ($timZone) {//POCOR-7581
            if ($entity->has('report_card_completed_on')) {
                if (!empty($timZone)) {
                    $date = new DateTime($entity->report_card_completed_on, new DateTimeZone($timZone));
                    $date->setTimezone(new DateTimeZone($timZone));
                    $value = $date->format('F d, Y h:i:s');
                }
            }
        }//POCOR-7581
        return $value;
        //END: POCOR-6716
    }

    public function onGetReportQueue(Event $event, Entity $entity)
    {
        if ($entity->has('report_card_id')) {
            $reportCardId = $entity->report_card_id;
        } else if (!is_null($this->request->query('report_card_id'))) {
            $reportCardId = $this->request->query('report_card_id');
        }

        $search = [
            'report_card_id' => $reportCardId,
            'institution_class_id' => $entity->institution_class_id,
            'student_id' => $entity->student_id,
            'institution_id' => $entity->institution_id,
            'education_grade_id' => $entity->education_grade_id,
            'academic_period_id' => $entity->academic_period_id
        ];

        $resultIndex = array_search($search, $this->reportProcessList);

        if ($resultIndex !== false) {
            $totalQueueCount = count($this->reportProcessList);
            return sprintf(__('%s of %s'), $resultIndex + 1, $totalQueueCount);
        } else {
            return '<i class="fa fa-minus"></i>';
        }
    }

    public function onGetOpenemisNo(Event $event, Entity $entity)
    {
        $value = '';
        if ($entity->has('user')) {
            $value = $entity->user->openemis_no;
        }
        return $value;
    }

    public function onGetReportCard(Event $event, Entity $entity)
    {
        $value = '';
        $params = $this->request->query;
        if ($entity->has('report_card_id')) {
            $reportCardId = $params['report_card_id'];
        } else if (!is_null($this->request->query('report_card_id'))) {
            // used if student report card record has not been created yet
            $reportCardId = $this->request->query('report_card_id');
        }

        if (!empty($reportCardId)) {
            $reportCardEntity = $this->ReportCards->find()->where(['id' => $reportCardId])->first();
            if (!empty($reportCardEntity)) {
                $value = $reportCardEntity->code_name;
            }
        }
        return $value;
    }

    public function onGetEmailStatus(Event $event, Entity $entity)
    {
        $emailStatuses = $this->ReportCardEmailProcesses->getEmailStatus();
        $value = '<i class="fa fa-minus"></i>';

        if ($entity->has('email_status_id')) {
            $value = $emailStatuses[$entity->email_status_id];

            if ($entity->email_status_id == $this->ReportCardEmailProcesses::ERROR && $entity->has('email_error_message')) {
                $value .= '&nbsp&nbsp;<i class="fa fa-exclamation-circle fa-lg table-tooltip icon-red" data-placement="right" data-toggle="tooltip" data-animation="false" data-container="body" title="" data-html="true" data-original-title="' . $entity->email_error_message . '"></i>';
            }
        }

        return $value;
    }

    public function generate(Event $event, ArrayObject $extra)
    {
        $params = $this->getQueryString();
        $hasTemplate = $this->ReportCards->checkIfHasTemplate($params['report_card_id']);

        if ($hasTemplate) {
            $checkReportCard = $this->checkReportCardsToBeProcess($params['institution_class_id'], $params['report_card_id'], $params['academic_period_id']);

            if ($checkReportCard) {
                $this->Alert->warning('ReportCardStatuses.checkReportCardTemplatePeriod');
                return $this->controller->redirect($this->url('index'));
                die;
            }

            $this->addReportCardsToProcesses($params['institution_id'], $params['institution_class_id'], $params['report_card_id'], $params['student_id']);
            $this->triggerGenerateAllReportCardsShell($params['institution_id'], $params['institution_class_id'], $params['report_card_id'], $params['student_id']);
            $this->Alert->warning('ReportCardStatuses.generate');
        } else {
            $url = $this->url('index');
            $this->Alert->warning('ReportCardStatuses.noTemplate');
        }

        $event->stopPropagation();
        return $this->controller->redirect($this->url('index'));
    }

    public function generateAll(Event $event, ArrayObject $extra)
    {
        $params = $this->getQueryString();
        $hasTemplate = $this->ReportCards->checkIfHasTemplate($params['report_card_id']);
        $institutionId = $this->Session->read('Institution.Institutions.id');//POCOR-6692

        if ($hasTemplate) {
            $checkReportCard = $this->checkReportCardsToBeProcess($params['institution_class_id'], $params['report_card_id'], $params['academic_period_id']);

            if ($checkReportCard) {
                $this->Alert->warning('ReportCardStatuses.checkReportCardTemplatePeriod');
                return $this->controller->redirect($this->url('index'));
                die;
            }

            $ReportCardProcesses = TableRegistry::get('ReportCard.ReportCardProcesses');
            //POCOR-6692 start
            if ($params['class_id'] == 'all') {
                $inProgress = $ReportCardProcesses->find()
                    ->where([
                        $ReportCardProcesses->aliasField('report_card_id') => $params['report_card_id'],
                        $ReportCardProcesses->aliasField('institution_class_id') => $params['institution_class_id'],
                        $ReportCardProcesses->aliasField('institution_id') => $institutionId
                    ])
                    ->count();
            } else {
                $inProgress = $ReportCardProcesses->find()
                    ->where([
                        //  $ReportCardProcesses->aliasField('report_card_id') => $params['report_card_id'],
                        $ReportCardProcesses->aliasField('institution_class_id') => $params['institution_class_id'],
                        $ReportCardProcesses->aliasField('institution_id') => $institutionId,
                        $ReportCardProcesses->aliasField('status  IN') => [1, 2]//POCOR-7455
                    ])
                    ->count();
            }
            //POCOR-6692 end     


            if (!$inProgress) {
                $this->addReportCardsToProcesses($params['institution_id'], $params['institution_class_id'], $params['report_card_id']);
                $this->triggerGenerateAllReportCardsShell($params['institution_id'], $params['institution_class_id'], $params['report_card_id']);
                $this->Alert->warning('ReportCardStatuses.generateAll');
            } else {
                $this->Alert->warning('ReportCardStatuses.inProgress');
            }
        } else {
            $this->Alert->warning('ReportCardStatuses.noTemplate');
        }

        $event->stopPropagation();
        return $this->controller->redirect($this->url('index'));
    }

    //POCOR-7321 start
    public function viewPDF(Event $event, ArrayObject $extra)
    {

        $params = $this->getQueryString();
        $statusArray = [self::GENERATED, self::PUBLISHED];
        $data = $this->StudentsReportCards->find()
            ->contain(['Students', 'ReportCards'])
            ->where([
                $this->StudentsReportCards->aliasField('report_card_id') => $params['report_card_id'],
                $this->StudentsReportCards->aliasField('student_id') => $params['student_id'],
                $this->StudentsReportCards->aliasField('institution_id') => $params['institution_id'],
                $this->StudentsReportCards->aliasField('academic_period_id') => $params['academic_period_id'],
                $this->StudentsReportCards->aliasField('academic_period_id') => $params['academic_period_id'],
                $this->StudentsReportCards->aliasField('education_grade_id') => $params['education_grade_id'],
                $this->StudentsReportCards->aliasField('status IN ') => $statusArray,
                $this->StudentsReportCards->aliasField('file_name IS NOT NULL'),
                $this->StudentsReportCards->aliasField('file_content IS NOT NULL')
            ])
            ->first();

        if (!empty($data)) {
            $fileName = $data->file_name;
            $fileNameData = explode(".", $fileName);
            $fileName = $fileNameData[0] . '.pdf';
            $pathInfo['extension'] = 'pdf';
            $file = $this->getFile($data->file_content_pdf);
            $fileType = 'image/jpg';
            if (array_key_exists($pathInfo['extension'], $this->fileTypes)) {
                $fileType = $this->fileTypes[$pathInfo['extension']];
            }
            // echo '<img src="data:image/jpg;base64,' .   base64_encode($file)  . '" />';
            header("Pragma: public", true);
            header("Expires: 0"); // set expiration time
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            // header("Content-Type: application/force-download");
            header("Content-Type: application/octet-stream");
            header("Content-Type: " . $fileType);
            header('Content-Disposition: inline; filename="' . $fileName . '"');
            echo $file;
        }
        exit();
    }

    //POCOR-7321 ends
    public function downloadAll(Event $event, ArrayObject $extra)
    {

        $params = $this->getQueryString();

        // only download report cards with generated or published status
        $statusArray = [self::GENERATED, self::PUBLISHED];

        $files = $this->StudentsReportCards->find()
            ->contain(['Students', 'ReportCards'])
            ->where([
                $this->StudentsReportCards->aliasField('institution_id') => $params['institution_id'],
                $this->StudentsReportCards->aliasField('institution_class_id') => $params['institution_class_id'],
                $this->StudentsReportCards->aliasField('report_card_id') => $params['report_card_id'],
                $this->StudentsReportCards->aliasField('status IN ') => $statusArray,
                $this->StudentsReportCards->aliasField('file_name IS NOT NULL'),
                $this->StudentsReportCards->aliasField('file_content IS NOT NULL')
            ])
            ->toArray();

        if (!empty($files)) {
            $path = WWW_ROOT . 'export' . DS . 'customexcel' . DS;
            $zipName = 'ReportCards' . '_' . date('Ymd') . 'T' . date('His') . '.zip';
            $filepath = $path . $zipName;

            $zip = new ZipArchive;
            $zip->open($filepath, ZipArchive::CREATE);
            foreach ($files as $file) {
                $zip->addFromString($file->file_name, $this->getFile($file->file_content));
            }
            $zip->close();

            header("Pragma: public", true);
            header("Expires: 0"); // set expiration time
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header("Content-Type: application/force-download");
            header("Content-Type: application/zip");
            header("Content-Length: " . filesize($filepath));
            header("Content-Disposition: attachment; filename=" . $zipName);
            readfile($filepath);
            ob_clean();
            flush();
            sleep(10);

            // delete file after download
            unlink($filepath);
        } else {
            $event->stopPropagation();
            $this->Alert->warning('ReportCardStatuses.noFilesToDownload');
            return $this->controller->redirect($this->url('index'));
        }
    }

    /*
     *  Download pdf in bulk
     * */
    public function downloadAllPdf(Event $event, ArrayObject $extra)
    {
        $params = $this->getQueryString();

        // only download report cards with generated or published status
        $statusArray = [self::GENERATED, self::PUBLISHED];

        $files = $this->StudentsReportCards->find()
            ->contain(['Students', 'ReportCards'])
            ->where([
                $this->StudentsReportCards->aliasField('institution_id') => $params['institution_id'],
                $this->StudentsReportCards->aliasField('institution_class_id') => $params['institution_class_id'],
                $this->StudentsReportCards->aliasField('report_card_id') => $params['report_card_id'],
                $this->StudentsReportCards->aliasField('status IN ') => $statusArray,
                $this->StudentsReportCards->aliasField('file_name IS NOT NULL'),
                $this->StudentsReportCards->aliasField('file_content IS NOT NULL'),
                $this->StudentsReportCards->aliasField('file_content_pdf IS NOT NULL')
            ])
            ->toArray();

        if (!empty($files)) {
            $path = WWW_ROOT . 'export' . DS . 'customexcel' . DS;
            $zipName = 'ReportCards' . '_' . date('Ymd') . 'T' . date('His') . '.zip';
            $filepath = $path . $zipName;

            $zip = new ZipArchive;
            $zip->open($filepath, ZipArchive::CREATE);

            foreach ($files as $file) {
                $fileName = $file->file_name;
                $fileNameData = explode(".", $fileName);
                $fileName = $fileNameData[0] . '.pdf';

                $zip->addFromString($fileName, $this->getFile($file->file_content_pdf));

            }
            $zip->close();

            header("Pragma: public", true);
            header("Expires: 0"); // set expiration time
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header("Content-Type: application/force-download");
            header("Content-Type: application/zip");
            header("Content-Length: " . filesize($filepath));
            header("Content-Disposition: attachment; filename=" . $zipName);
            readfile($filepath);

            // delete file after download
            unlink($filepath);
        } else {
            $event->stopPropagation();
            $this->Alert->warning('ReportCardStatuses.noFilesToDownload');
            return $this->controller->redirect($this->url('index'));
        }
    }

    public function publish(Event $event, ArrayObject $extra)
    {
        $params = $this->getQueryString();
        $result = $this->StudentsReportCards->updateAll(['status' => self::PUBLISHED], $params);
        $this->Alert->success('ReportCardStatuses.publish');
        $event->stopPropagation();
        return $this->controller->redirect($this->url('index'));
    }

    public function publishAll(Event $event, ArrayObject $extra)
    {
        $params = $this->getQueryString();

        // only publish report cards with generated status to published status
        $result = $this->StudentsReportCards->updateAll(['status' => self::PUBLISHED], [
            $params,
            'status' => self::GENERATED
        ]);

        if ($result) {
            $this->Alert->success('ReportCardStatuses.publishAll');
        } else {
            $this->Alert->warning('ReportCardStatuses.noFilesToPublish');
        }

        $event->stopPropagation();
        return $this->controller->redirect($this->url('index'));
    }

    public function unpublish(Event $event, ArrayObject $extra)
    {
        $params = $this->getQueryString();
        $result = $this->StudentsReportCards->updateAll(['status' => self::NEW_REPORT], $params);
        $this->Alert->success('ReportCardStatuses.unpublish');
        $event->stopPropagation();
        return $this->controller->redirect($this->url('index'));
    }

    public function unpublishAll(Event $event, ArrayObject $extra)
    {
        $params = $this->getQueryString();

        // only unpublish report cards with published status to new status
        $result = $this->StudentsReportCards->updateAll(['status' => self::NEW_REPORT], [
            $params,
            'status' => self::PUBLISHED
        ]);

        if ($result) {
            $this->Alert->success('ReportCardStatuses.unpublishAll');
        } else {
            $this->Alert->warning('ReportCardStatuses.noFilesToUnpublish');
        }

        $event->stopPropagation();
        return $this->controller->redirect($this->url('index'));
    }

    public function emailPdf(Event $event, ArrayObject $extra)
    {
        $params = $this->getQueryString();
        $this->addReportCardsToEmailProcesses($params['institution_id'], $params['institution_class_id'], $params['report_card_id'], $params['student_id']);
        $this->triggerEmailAllReportCardsShell($params['institution_id'], $params['institution_class_id'], $params['report_card_id'], $params['student_id']);
        $this->Alert->warning('ReportCardStatuses.email');

        $event->stopPropagation();
        return $this->controller->redirect($this->url('index'));
    }

    public function emailAllPdf(Event $event, ArrayObject $extra)
    {
        $params = $this->getQueryString();

        $inProgress = $this->ReportCardEmailProcesses->find()
            ->where([
                $this->ReportCardEmailProcesses->aliasField('report_card_id') => $params['report_card_id'],
                $this->ReportCardEmailProcesses->aliasField('institution_class_id') => $params['institution_class_id'],
                $this->ReportCardEmailProcesses->aliasField('status') => $this->ReportCardEmailProcesses::SENDING
            ])
            ->count();

        if (!$inProgress) {
            $this->addReportCardsToEmailProcesses($params['institution_id'], $params['institution_class_id'], $params['report_card_id']);
            $this->triggerEmailAllReportCardsShell($params['institution_id'], $params['institution_class_id'], $params['report_card_id']);

            $this->Alert->warning('ReportCardStatuses.emailAll');
        } else {
            $this->Alert->warning('ReportCardStatuses.emailInProgress');
        }

        $event->stopPropagation();
        return $this->controller->redirect($this->url('index'));
    }

    private function addReportCardsToProcesses($institutionId, $institutionClassId, $reportCardId, $studentId = null)
    {
        //POCOR-7067 Starts
        $ConfigItems = TableRegistry::get('Configuration.ConfigItems');
        $timeZone = $ConfigItems->value("time_zone");
        date_default_timezone_set($timeZone);//POCOR-7067 Ends
        Log::write('debug', 'Initialize Add All Report Cards ' . $reportCardId . ' for Class ' . $institutionClassId . ' to processes (' . Time::now() . ')');

        $ReportCardProcesses = TableRegistry::get('ReportCard.ReportCardProcesses');
        $classStudentsTable = TableRegistry::get('Institution.InstitutionClassStudents');
        $where = [];
        $where[$classStudentsTable->aliasField('institution_class_id')] = $institutionClassId;
        if (!is_null($studentId)) {
            $checkgpaStudent = $studentId; //POCOR-7656
            $where[$classStudentsTable->aliasField('student_id')] = $studentId;
        }
        $classStudents = $classStudentsTable->find()
            ->select([
                $classStudentsTable->aliasField('student_id'),
                $classStudentsTable->aliasField('institution_id'),
                $classStudentsTable->aliasField('academic_period_id'),
                $classStudentsTable->aliasField('education_grade_id'),
                $classStudentsTable->aliasField('institution_class_id')
            ])
            ->where($where)
            ->toArray();


        foreach ($classStudents as $student) {
            // Report card processes
            $checkgpaStudent = $student->student_id;//POCOR-7656
            $idKeys = [
                'report_card_id' => $reportCardId,
                'institution_class_id' => $student->institution_class_id,
                'student_id' => $student->student_id
            ];
            $educationGradeId = $student->education_grade_id;
            $data = [
                'status' => $ReportCardProcesses::NEW_REPORT, //POCOR-7989
                'institution_id' => $student->institution_id,
                'education_grade_id' => $student->education_grade_id,
                'academic_period_id' => $student->academic_period_id,
                'created' => date('Y-m-d H:i:s')
            ];
            $obj = array_merge($idKeys, $data);
            $newEntity = $ReportCardProcesses->newEntity($obj);
            $ReportCardProcesses->save($newEntity);
            // end

            // Report card email processes
            $emailIdKeys = $idKeys;
            if ($this->ReportCardEmailProcesses->exists($emailIdKeys)) {
                $reportCardEmailProcessEntity = $this->ReportCardEmailProcesses->find()
                    ->where($emailIdKeys)
                    ->first();
                $this->ReportCardEmailProcesses->delete($reportCardEmailProcessEntity);
            }
            // end
            $getGpa = $this->addGpaReportCards($checkgpaStudent, $reportCardId, $student->academic_period_id);//POCOR-7318 get student GPA//POCOR-7656
            // Student report card
            $recordIdKeys = [
                'report_card_id' => $reportCardId,
                'student_id' => $student->student_id,
                'academic_period_id' => $student->academic_period_id,
                'education_grade_id' => $student->education_grade_id,
                'institution_id' => $student->institution_id //POCOR-8285
            ];
            if ($this->StudentsReportCards->exists($recordIdKeys)) {
                $studentsReportCardEntity = $this->StudentsReportCards->find()
                    ->where($recordIdKeys)
                    ->first();

                $newData = [
                    'status' => $this->StudentsReportCards::IN_PROGRESS,
                    'started_on' => date('Y-m-d H:i:s'),
                    'completed_on' => NULL,
                    'file_name' => NULL,
                    'file_content' => NULL,
                    'institution_class_id' => $studentsReportCardEntity->institution_class_id,
                    'gpa' => $getGpa,
                ];
                $newEntity = $this->StudentsReportCards->patchEntity($studentsReportCardEntity, $newData);

                if (!$this->StudentsReportCards->save($newEntity)) {
                    Log::write('debug', 'Error Add All Report Cards ' . $reportCardId . ' for Class ' . $institutionClassId . ' to processes (' . Time::now() . ')');
                    Log::write('debug', $newEntity->errors());
                }
            } else {
                //POCOR-6431[START]
                $StudentsReportCards = TableRegistry::get('Institution.InstitutionStudentsReportCards');
                $ReportCardProcesses = TableRegistry::get('ReportCard.ReportCardProcesses');
                if (!$StudentsReportCards->exists($recordIdKeys)) {
                    // insert student report card record if it does not exist
                    $recordIdKeys['status'] = $StudentsReportCards::IN_PROGRESS;
                    $recordIdKeys['started_on'] = date('Y-m-d H:i:s');
                    $recordIdKeys['gpa'] = $getGpa;
                    $recordIdKeys['institution_class_id'] = $student->institution_class_id; // POCOR-8285
                    $newEntity = $StudentsReportCards->newEntity($recordIdKeys);
                    $StudentsReportCards->save($newEntity);
                } else {
                    // update status to in progress if record exists
                    $StudentsReportCards->updateAll([
                        'status' => $StudentsReportCards::IN_PROGRESS,
                        'started_on' => date('Y-m-d H:i:s'),
                        'gpa' => $getGpa,
                    ], $recordIdKeys);
                }
                //POCOR-6431[END]
            }
            // end
        }
        Log::write('debug', 'End Add All Report Cards ' . $reportCardId . ' for Class ' . $institutionClassId . ' to processes (' . Time::now() . ')');
    }

    private function triggerGenerateAllReportCardsShell($institutionId, $institutionClassId, $reportCardId, $studentId = null)
    {
        $SystemProcesses = TableRegistry::get('SystemProcesses');
        $runningProcess = $SystemProcesses->getRunningProcesses($this->registryAlias());

        foreach ($runningProcess as $key => $processData) {
            $systemProcessId = $processData['id'];
            $pId = !empty($processData['process_id']) ? $processData['process_id'] : 0;
            $createdDate = $processData['created'];

            $expiryDate = clone($createdDate);
            $expiryDate->addMinutes(30);
            $today = Time::now();

            if ($expiryDate < $today) {
                $SystemProcesses->updateProcess($systemProcessId, Time::now(), $SystemProcesses::COMPLETED);
                $SystemProcesses->killProcess($pId);
            }
        }

        if (count($runningProcess) <= self::MAX_PROCESSES) {
            $processModel = $this->registryAlias();
            $passArray = [
                'institution_id' => $institutionId,
                'institution_class_id' => $institutionClassId,
                'report_card_id' => $reportCardId
            ];
            if (!is_null($studentId)) {
                $passArray['student_id'] = $studentId;
            }
            $params = json_encode($passArray);

            $args = $processModel . " " . $params;

            $cmd = ROOT . DS . 'bin' . DS . 'cake GenerateAllReportCards ' . $args;
            $logs = ROOT . DS . 'logs' . DS . 'GenerateAllReportCards.log & echo $!';
            $shellCmd = $cmd . ' >> ' . $logs;
            // print_r($shellCmd);die('ok');
            try {
                $pid = exec($shellCmd);
                Log::write('debug', $shellCmd);
            } catch (\Exception $ex) {
                Log::write('error', __METHOD__ . ' exception when generate all report cards : ' . $ex);
            }
        }
    }

    private function addReportCardsToEmailProcesses($institutionId, $institutionClassId, $reportCardId, $studentId = null)
    {
        //POCOR-7067 Starts
        $ConfigItems = TableRegistry::get('Configuration.ConfigItems');
        $timeZone = $ConfigItems->value("time_zone");
        date_default_timezone_set($timeZone);//POCOR-7067 Ends
        Log::write('debug', 'Initialize Add All Report Cards ' . $reportCardId . ' for Class ' . $institutionClassId . ' to email processes (' . Time::now() . ')');

        $classStudentsTable = TableRegistry::get('Institution.InstitutionClassStudents');

        $where = [];
        $where[$classStudentsTable->aliasField('institution_class_id')] = $institutionClassId;
        if (!is_null($studentId)) {
            $where[$classStudentsTable->aliasField('student_id')] = $studentId;
        }
        $classStudents = $classStudentsTable->find()
            ->select([
                $classStudentsTable->aliasField('student_id'),
                $classStudentsTable->aliasField('institution_id'),
                $classStudentsTable->aliasField('academic_period_id'),
                $classStudentsTable->aliasField('education_grade_id'),
                $classStudentsTable->aliasField('institution_class_id')
            ])
            ->innerJoin([$this->StudentsReportCards->alias() => $this->StudentsReportCards->table()],
                [
                    $this->StudentsReportCards->aliasField('student_id = ') . $classStudentsTable->aliasField('student_id'),
                    $this->StudentsReportCards->aliasField('institution_id = ') . $classStudentsTable->aliasField('institution_id'),
                    $this->StudentsReportCards->aliasField('academic_period_id = ') . $classStudentsTable->aliasField('academic_period_id'),
                    $this->StudentsReportCards->aliasField('education_grade_id = ') . $classStudentsTable->aliasField('education_grade_id'),
                    $this->StudentsReportCards->aliasField('institution_class_id = ') . $classStudentsTable->aliasField('institution_class_id'),
                    $this->StudentsReportCards->aliasField('report_card_id = ') . $reportCardId,
                    $this->StudentsReportCards->aliasField('status') => self::PUBLISHED
                ]
            )
            ->where($where)
            ->toArray();

        foreach ($classStudents as $student) {
            // Report card email processes
            $idKeys = [
                'report_card_id' => $reportCardId,
                'institution_class_id' => $student->institution_class_id,
                'student_id' => $student->student_id
            ];

            $data = [
                'status' => $this->ReportCardEmailProcesses::SENDING,
                'institution_id' => $student->institution_id,
                'education_grade_id' => $student->education_grade_id,
                'academic_period_id' => $student->academic_period_id,
                'created' => date('Y-m-d H:i:s')
            ];
            $obj = array_merge($idKeys, $data);
            $newEntity = $this->ReportCardEmailProcesses->newEntity($obj);
            $this->ReportCardEmailProcesses->save($newEntity);
            // end
        }

        Log::write('debug', 'End Add All Report Cards ' . $reportCardId . ' for Class ' . $institutionClassId . ' to email processes (' . Time::now() . ')');
    }

    private function triggerEmailAllReportCardsShell($institutionId, $institutionClassId, $reportCardId, $studentId = null)
    {
        $SystemProcesses = TableRegistry::get('SystemProcesses');
        $runningProcess = $SystemProcesses->getRunningProcesses($this->ReportCardEmailProcesses->registryAlias());

        // to-do: add logic to purge shell which is 30 minutes old

        if (count($runningProcess) <= self::MAX_PROCESSES) {
            $name = 'EmailAllReportCards';
            $pid = '';
            $processModel = $this->ReportCardEmailProcesses->registryAlias();
            $eventName = '';
            $passArray = [
                'institution_id' => $institutionId,
                'institution_class_id' => $institutionClassId,
                'report_card_id' => $reportCardId
            ];
            if (!is_null($studentId)) {
                $name = 'EmailReportCards';
                $passArray['student_id'] = $studentId;
            }
            $params = json_encode($passArray);
            $systemProcessId = $SystemProcesses->addProcess($name, $pid, $processModel, $eventName, $params);
            $SystemProcesses->updateProcess($systemProcessId, null, $SystemProcesses::RUNNING, 0);

            $args = '';
            $args .= !is_null($systemProcessId) ? ' ' . $systemProcessId : '';

            $cmd = ROOT . DS . 'bin' . DS . 'cake EmailAllReportCards' . $args;
            $logs = ROOT . DS . 'logs' . DS . 'EmailAllReportCards.log & echo $!';
            $shellCmd = $cmd . ' >> ' . $logs;

            try {
                $pid = exec($shellCmd);
                Log::write('debug', $shellCmd);
            } catch (\Exception $ex) {
                Log::write('error', __METHOD__ . ' exception when email all report cards : ' . $ex);
            }
        }
    }

    private function getFile($phpResourceFile)
    {
        $file = '';
        while (!feof($phpResourceFile)) {
            $file .= fread($phpResourceFile, 8192);
        }
        fclose($phpResourceFile);

        return $file;
    }

    private function checkReportCardsToBeProcess($institutionClassId, $reportCardId, $academicPeriodId = null)
    {
        $classStudentsTable = TableRegistry::get('Institution.InstitutionClassStudents');
        $where = [];
        $where[$classStudentsTable->aliasField('institution_class_id')] = $institutionClassId;
        $where[$classStudentsTable->aliasField('academic_period_id')] = $academicPeriodId;
        $classStudents = $classStudentsTable->find()
            ->select([
                $classStudentsTable->aliasField('education_grade_id'),
                $classStudentsTable->aliasField('academic_period_id')
            ])
            ->where($where)
            ->first();

        if (empty($classStudents)) {
            return false;
        }

        $condition = [];
        $Assessments = TableRegistry::get('Assessment.Assessments');
        $entityAssessment = $Assessments->find()
            ->where([
                $Assessments->aliasField('academic_period_id') => $classStudents->academic_period_id,
                $Assessments->aliasField('education_grade_id') => $classStudents->education_grade_id
            ])
            ->first();

        if (!empty($entityAssessment)) {
            $condition['assessment_id'] = $entityAssessment->id;
        }

        $ReportCards = TableRegistry::get('ReportCard.ReportCards');
        $entityReportCards = $ReportCards->get($reportCardId);

        $condition['report_card_start_date'] = $entityReportCards->start_date;
        $condition['report_card_end_date'] = $entityReportCards->end_date;

        if (array_key_exists('assessment_id', $condition)
            && array_key_exists('report_card_start_date', $condition)
            && array_key_exists('report_card_end_date', $condition)
        ) {

            $AssessmentPeriods = TableRegistry::get('Assessment.AssessmentPeriods');
            $entityAssessmentPeriods = $AssessmentPeriods->find()
                ->where([
                    $AssessmentPeriods->aliasField('assessment_id') => $condition['assessment_id'],
                    $AssessmentPeriods->aliasField('start_date >= ') => $condition['report_card_start_date'],
                    $AssessmentPeriods->aliasField('end_date <= ') => $condition['report_card_end_date']
                ])
                ->order([$AssessmentPeriods->aliasField('start_date')]);

            if (($entityAssessmentPeriods->count() > 0)) {

                return false;
            } else {
                //POCOR-7400 start
                $res = $this->canGenerateAnyDate($reportCardId);
                if ($res) {
                    return false;
                }
                //POCOR-7400 end

                return true;
            }

        }

        return false;

    }

    /**
     * send email of single student in excel format
     * @author Poonam Kharka <poonam.kharka@mail.valuecoders.com>
     * @ticket POCOR-6836
     */
    public function emailExcel(Event $event, ArrayObject $extra)
    {
        $params = $this->getQueryString();
        $this->addReportCardsToEmailProcesses($params['institution_id'], $params['institution_class_id'], $params['report_card_id'], $params['student_id']);
        $this->triggerEmailAllExcelReportCardsShell($params['institution_id'], $params['institution_class_id'], $params['report_card_id'], $params['student_id']);
        $this->Alert->warning('ReportCardStatuses.email');

        $event->stopPropagation();
        return $this->controller->redirect($this->url('index'));
    }

    /**
     * send email of all students in excel format
     * @author Poonam Kharka <poonam.kharka@mail.valuecoders.com>
     * @ticket POCOR-6836
     */
    public function emailAllExcel(Event $event, ArrayObject $extra)
    {
        $params = $this->getQueryString();

        $inProgress = $this->ReportCardEmailProcesses->find()
            ->where([
                $this->ReportCardEmailProcesses->aliasField('report_card_id') => $params['report_card_id'],
                $this->ReportCardEmailProcesses->aliasField('institution_class_id') => $params['institution_class_id'],
                $this->ReportCardEmailProcesses->aliasField('status') => $this->ReportCardEmailProcesses::SENDING
            ])
            ->count();

        if (!$inProgress) {
            $this->addReportCardsToEmailProcesses($params['institution_id'], $params['institution_class_id'], $params['report_card_id']);
            $this->triggerEmailAllExcelReportCardsShell($params['institution_id'], $params['institution_class_id'], $params['report_card_id']);

            $this->Alert->warning('ReportCardStatuses.emailAll');
        } else {
            $this->Alert->warning('ReportCardStatuses.emailInProgress');
        }

        $event->stopPropagation();
        return $this->controller->redirect($this->url('index'));
    }

    /**
     * trigger event to sent studnet's email in excel format
     * @author Poonam Kharka <poonam.kharka@mail.valuecoders.com>
     * @ticket POCOR-6836
     */
    private function triggerEmailAllExcelReportCardsShell($institutionId, $institutionClassId, $reportCardId, $studentId = null)
    {
        $SystemProcesses = TableRegistry::get('SystemProcesses');
        $runningProcess = $SystemProcesses->getRunningProcesses($this->ReportCardEmailProcesses->registryAlias());

        // to-do: add logic to purge shell which is 30 minutes old

        if (count($runningProcess) <= self::MAX_PROCESSES) {
            $name = 'EmailAllReportExcelCards';
            $pid = '';
            $processModel = $this->ReportCardEmailProcesses->registryAlias();
            $eventName = '';
            $passArray = [
                'institution_id' => $institutionId,
                'institution_class_id' => $institutionClassId,
                'report_card_id' => $reportCardId
            ];
            if (!is_null($studentId)) {
                $name = 'EmailReportCardsExcel';
                $passArray['student_id'] = $studentId;
            }
            $params = json_encode($passArray);
            $systemProcessId = $SystemProcesses->addProcess($name, $pid, $processModel, $eventName, $params);
            $SystemProcesses->updateProcess($systemProcessId, null, $SystemProcesses::RUNNING, 0);

            $args = '';
            $args .= !is_null($systemProcessId) ? ' ' . $systemProcessId : '';

            $cmd = ROOT . DS . 'bin' . DS . 'cake EmailAllExcelReportCards' . $args;
            $logs = ROOT . DS . 'logs' . DS . 'EmailAllExcelReportCardsExcel.log & echo $!';
            $shellCmd = $cmd . ' >> ' . $logs;

            try {
                $pid = exec($shellCmd);
                Log::write('debug', $shellCmd);
            } catch (\Exception $ex) {
                Log::write('error', __METHOD__ . ' exception when email all report cards : ' . $ex);
            }
        }
    }

    //POCOR-7400 start //POCOR-7998 refactored
    public function canGenerateAnyDate($report_card_id)
    {
        $security_role_ids = $this->getUserSecurityRoles();
        $ExcludedSecurityRoleCount = -1;
        if (!empty($security_role_ids)) {
            $ExcludedSecurityRoleTable = TableRegistry::get('report_card_excluded_security_roles');
            $ExcludedSecurityRoleCount = $ExcludedSecurityRoleTable->find('all')
                ->where([
                    'security_role_id IN' => $security_role_ids,
                    'report_card_id' => $report_card_id
                ])->count();
        }

        if (($ExcludedSecurityRoleCount > 0)) {
            return true;
        } else {
            return false;
        }
    }
    //POCOR-7400 end

    /*
    * POCOR-7318
    * query again change in POCOR-7628
    **/

    //POCOR-8020 :: modified the query
    private function addGpaReportCards($checkgpaStudent, $reportCardId,$selectedAcademicPeriodId)//POCOR-7807
    {
        $studentId = $checkgpaStudent;//POCOR-7656
        $this->AcademicPeriods = TableRegistry::get('AcademicPeriod.AcademicPeriods');
        $academicPeriodOptions = $this->AcademicPeriods->getYearList(['isEditable' => true]);
        // $selectedAcademicPeriodId =  $this->AcademicPeriods->getCurrent();//POCOR-7807 to check fot previous academic periods
        $gpa = 0.00;
        $connection = ConnectionManager::get('default');
        //POCOR-7876 start
        $statement = $connection->prepare("SELECT report_cards.id report_card_id
        ,subq3.student_id
        ,subq3.education_grade_id
        ,subq3.academic_period_id
        ,MAX(ROUND(subq3.gpa_per_student, 2)) gpa_per_student
    FROM
    (
        SELECT subq.academic_period_id
            ,subq.education_grade_id
            ,subq.assessment_period_start_date
            ,subq.assessment_period_end_date
            ,subq.institution_id
            ,subq.student_id
            ,ROUND(AVG(IFNULL(assessment_grading_options.point, 0)), 2) gpa_per_student
        FROM 
        (
            SELECT institution_subject_students.academic_period_id
                ,institution_subject_students.institution_id
                ,institution_subject_students.education_grade_id
                ,institution_subject_students.education_subject_id
                ,institution_subject_students.student_id
                ,term_info.academic_term
                ,term_info.assessment_period_start_date
                ,term_info.assessment_period_end_date
                ,term_info.assessment_grading_type_id
                ,IFNULL(subq2.total_mark, 0) total_mark
            FROM institution_subject_students
            INNER JOIN 
            (
                SELECT assessments.academic_period_id
                    ,assessments.education_grade_id
                    ,IFNULL(assessment_periods.academic_term, 1) academic_term
                    ,MIN(assessment_periods.start_date) assessment_period_start_date
                    ,MAX(assessment_periods.end_date) assessment_period_end_date
                    ,MAX(assessments.assessment_grading_type_id) assessment_grading_type_id
                FROM assessment_periods
                INNER JOIN assessments
                ON assessments.id = assessment_periods.assessment_id
                WHERE assessments.academic_period_id = $selectedAcademicPeriodId
                GROUP BY assessments.academic_period_id
                    ,assessments.education_grade_id
                    ,IFNULL(assessment_periods.academic_term, 1)
            ) term_info
            ON term_info.academic_period_id = institution_subject_students.academic_period_id
            AND term_info.education_grade_id = institution_subject_students.education_grade_id
            LEFT JOIN 
            (
                SELECT assessment_item_results.academic_period_id
                        ,assessment_item_results.institution_id
                        ,assessment_item_results.education_grade_id
                        ,assessment_item_results.education_subject_id
                        ,assessment_item_results.student_id
                        ,IFNULL(assessment_periods.academic_term, 1) academic_term
                        ,IFNULL(ROUND(SUM(assessment_item_results.marks * assessment_periods.weight) / IFNULL(assessment_grading_types.max, CEILING(MAX(assessment_item_results.marks) / 10) * 10) * 100, 2), '') total_mark
                FROM assessment_item_results
                    INNER JOIN 
                    (
                        SELECT assessment_item_results.academic_period_id
                            ,assessment_item_results.institution_id
                            ,assessment_item_results.education_grade_id
                            ,assessment_item_results.student_id
                            ,assessment_item_results.assessment_id
                            ,assessment_item_results.education_subject_id
                            ,assessment_item_results.assessment_period_id
                            ,MAX(assessment_item_results.created) latest_created
                        FROM assessment_item_results
                        WHERE assessment_item_results.academic_period_id = $selectedAcademicPeriodId
                        AND assessment_item_results.student_id = $studentId
                        GROUP BY assessment_item_results.academic_period_id
                            ,assessment_item_results.institution_id
                            ,assessment_item_results.education_grade_id
                            ,assessment_item_results.student_id
                            ,assessment_item_results.assessment_id
                            ,assessment_item_results.education_subject_id
                            ,assessment_item_results.assessment_period_id
                    ) latest_grades
                    ON latest_grades.academic_period_id = assessment_item_results.academic_period_id
                    AND latest_grades.institution_id = assessment_item_results.institution_id
                    AND latest_grades.education_grade_id = assessment_item_results.education_grade_id
                    AND latest_grades.student_id = assessment_item_results.student_id
                    AND latest_grades.assessment_id = assessment_item_results.assessment_id
                    AND latest_grades.education_subject_id = assessment_item_results.education_subject_id
                    AND latest_grades.assessment_period_id = assessment_item_results.assessment_period_id
                    AND latest_grades.latest_created = assessment_item_results.created
                    LEFT JOIN assessment_grading_options
                    ON assessment_grading_options.id = assessment_item_results.assessment_grading_option_id
                    LEFT JOIN assessment_grading_types
                    ON assessment_grading_types.id = assessment_grading_options.assessment_grading_type_id
                    INNER JOIN assessment_periods
                    ON assessment_periods.id = assessment_item_results.assessment_period_id
                    INNER JOIN education_subjects
                    ON education_subjects.id = assessment_item_results.education_subject_id
                    WHERE assessment_item_results.academic_period_id = $selectedAcademicPeriodId
                    AND assessment_item_results.student_id =$studentId
                    GROUP BY assessment_item_results.academic_period_id
                        ,assessment_item_results.institution_id
                        ,assessment_item_results.education_grade_id
                        ,assessment_item_results.education_subject_id
                        ,assessment_item_results.student_id
                        ,assessment_periods.academic_term
            ) subq2
            ON subq2.academic_period_id = institution_subject_students.academic_period_id
            AND subq2.institution_id = institution_subject_students.institution_id
            AND subq2.education_grade_id = institution_subject_students.education_grade_id
            AND subq2.student_id = institution_subject_students.student_id
            AND subq2.education_subject_id = institution_subject_students.education_subject_id
            AND subq2.academic_term = term_info.academic_term
            WHERE institution_subject_students.academic_period_id = $selectedAcademicPeriodId
            AND institution_subject_students.student_id = $studentId
            GROUP BY institution_subject_students.academic_period_id
                ,institution_subject_students.institution_id
                ,institution_subject_students.education_grade_id
                ,institution_subject_students.education_subject_id
                ,institution_subject_students.student_id
                ,term_info.academic_term
        ) subq
        LEFT JOIN assessment_grading_options
        ON subq.total_mark >= assessment_grading_options.min 
        AND subq.total_mark <= assessment_grading_options.max
        AND subq.assessment_grading_type_id = assessment_grading_options.assessment_grading_type_id
        GROUP BY subq.academic_period_id
            ,subq.institution_id
            ,subq.education_grade_id
            ,subq.student_id
            ,subq.academic_term
    ) subq3
    INNER JOIN report_cards
    ON report_cards.academic_period_id = subq3.academic_period_id
    AND report_cards.education_grade_id = subq3.education_grade_id
    AND subq3.assessment_period_end_date BETWEEN report_cards.start_date AND report_cards.end_date
    WHERE report_cards.id=$reportCardId
    GROUP BY report_cards.id
        ,subq3.student_id
        ,subq3.academic_period_id
        ,subq3.education_grade_id");
        //POCOR-7876 end
        $statement->execute();
        $result = $statement->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($result)) {
            foreach ($result as $val) {
                $gpa = $val['gpa_per_student'];

            }
        }
        return $gpa;
    }

    //POCOR-7998:start
    /**
     * @return array
     */
    private function getUserSecurityRoles()
    {
        $SecurityGroupUsers = TableRegistry::get('security_group_users');
        $current_user = $this->Auth->user('id');
        $SecurityGroupUsersData = $SecurityGroupUsers
            ->find()
            ->select(['security_role_id'])
            ->distinct(['security_role_id'])
            ->where([
                $SecurityGroupUsers->aliasField('security_user_id') => $current_user
            ])
            ->group([$SecurityGroupUsers->aliasField('security_role_id')])
            ->toArray();
        $security_role_ids = array_column($SecurityGroupUsersData, 'security_role_id');
        if (empty($security_role_ids)) {
            $security_role_ids = [0];
        }
        return $security_role_ids;
    }

    /**
     * @param array $buttons
     * @param $downloadName
     * @param $downloadUrl
     * @param $buttonName
     * @return array
     */
    private function getDownloadButtons(array $buttons, $downloadName, $downloadUrl, $buttonName)
    {
        $indexAttr = ['role' => 'menuitem', 'tabindex' => '-1', 'escape' => false];
        $isAdmin = $this->AccessControl->isAdmin();

        if (!$isAdmin) {
            $security_role_ids = $this->getUserSecurityRoles();
            $SecurityRoleFunctions = TableRegistry::get('Security.SecurityRoleFunctions');
            $SecurityFunctions = TableRegistry::get('Security.SecurityFunctions');
            $where = [$SecurityRoleFunctions->aliasField('security_role_id IN') => $security_role_ids];
        }

        $canUserDownload = false;

        if ($isAdmin) {
            $canUserDownload = true;
        }
        if (!$isAdmin) {
            $canDownloadData = $SecurityFunctions
                ->find()
                ->where([
                    $SecurityFunctions->aliasField('name') => $downloadName])
                ->first();

            //$SecurityRoleFunctions = TableRegistry::get('Security.SecurityRoleFunctions');
            $canUserDownloadData = $SecurityRoleFunctions
                ->find()
                ->where([
                    $SecurityRoleFunctions->aliasField('security_function_id') => $canDownloadData->id,
                    $SecurityRoleFunctions->aliasField('_execute') => 1,
                    $where
                ])->first();
            $canUserDownload = !empty($canUserDownloadData);
        }

        if ($canUserDownload) {

            $buttons[$buttonName] = [
                'label' => '<i class="fa kd-download"></i>' . __($downloadName),
                'attr' => $indexAttr,
                'url' => $downloadUrl
            ];
        }
        return $buttons;
    }

    /**
     * @param array $buttons
     * @param array $params
     * @return array
     */
    private function addDownloadExcelButton(array $buttons, array $params)
    {
        $downloadExcelName = 'Download Excel';
        $buttonName = 'download';
        $downloadExcelUrl = [
            'plugin' => 'Institution',
            'controller' => 'Institutions',
            'action' => 'InstitutionStudentsReportCards',
            '0' => $buttonName,
            '1' => $this->paramsEncode($params)
        ];
        $buttons = $this->getDownloadButtons($buttons, $downloadExcelName, $downloadExcelUrl, $buttonName);
        return $buttons;
    }

    /**
     * @param array $buttons
     * @param array $params
     * @return array
     */
    private function addDownloadPdfButton(array $buttons, array $params)
    {
        $downloadPdfName = 'Download Pdf';
        $buttonName = 'downloadPdf';
        $downloadPdfUrl = [
            'plugin' => 'Institution',
            'controller' => 'Institutions',
            'action' => 'InstitutionStudentsReportCards',
            '0' => $buttonName,
            '1' => $this->paramsEncode($params)
        ];
        $buttons = $this->getDownloadButtons($buttons, $downloadPdfName, $downloadPdfUrl, $buttonName);
        return $buttons;
    }

    /**
     * @param array $buttons
     * @param array $params
     * @return array
     */
    private function addViewPdfButton(array $buttons, array $params)
    {

        if (isset($buttons['downloadPdf'])) {
            $viewPdfUrl = $this->setQueryString($this->url('viewPDF'), $params);
            $buttons['viewPdf'] = [
                'label' => '<i class="fa fa-eye"></i>' . __('View PDF'),
                'attr' => ['role' => 'menuitem',
                    'tabindex' => '-1',
                    'escape' => false,
                    'target' => '_blank'],
                'url' => $viewPdfUrl
            ];
        }
        return $buttons;
    }

    /**
     * @param array $buttons
     * @param $params
     * @return array
     */
    private function addGenerateButton(array $buttons, $params)
    {
        $indexAttr = ['role' => 'menuitem', 'tabindex' => '-1', 'escape' => false];
        $reportCardId = $this->request->query('report_card_id');
        $isAdmin = $this->AccessControl->isAdmin();
        if (!$isAdmin) {
            $security_role_ids = $this->getUserSecurityRoles();
            $SecurityRoleFunctions = TableRegistry::get('Security.SecurityRoleFunctions');
            $SecurityFunctions = TableRegistry::get('Security.SecurityFunctions');
            $where = [$SecurityRoleFunctions->aliasField('security_role_id IN') => $security_role_ids];
        }
        $canGenerate = $this->AccessControl->check(['Institutions', 'ReportCardStatuses', 'generate']);


        if ($canGenerate) {
            $generateUrl = $this->setQueryString($this->url('generate'), $params);
            $canGenerateAnyDate = false;
            if ($isAdmin) {
                $canGenerateAnyDate = true;
            }
            if (!$canGenerateAnyDate) {
                $canGenerateAnyDate = $this->canGenerateAnyDate($reportCardId);  //POCOR-7551
            }
            if ($canGenerateAnyDate) {
                $buttons['generate'] = [
                    'label' => '<i class="fa fa-refresh"></i>' . __('Generate'),
                    'attr' => $indexAttr,
                    'url' => $generateUrl
                ];
            }

            if (!$canGenerateAnyDate) {
                $reportCard = $this->ReportCards
                    ->find()
                    ->where([
                        $this->ReportCards->aliasField('id') => $reportCardId])
                    ->first();

                if (!empty($reportCard->generate_start_date)) {
                    $generateStartDate = $reportCard->generate_start_date->format('Y-m-d');
                }

                if (!empty($reportCard->generate_end_date)) {
                    $generateEndDate = $reportCard->generate_end_date->format('Y-m-d');
                }
                $date = Time::now()->format('Y-m-d');

                //POCOR-6838: Start

                $canGenerateData = $SecurityFunctions
                    ->find()
                    ->where([
                        $SecurityFunctions->aliasField('name') => 'Generate'])
                    ->first();

                $canUserGenerateData = $SecurityRoleFunctions
                    ->find()
                    ->where([
                        $SecurityRoleFunctions->aliasField('security_function_id') => $canGenerateData->id,
                        $where
                    ])
                    ->first();

                if ($canUserGenerateData) {
                    if ((!empty($generateStartDate)
                            && !empty($generateEndDate))
                        && ($date >= $generateStartDate && $date <= $generateEndDate)) {
                        $buttons['generate'] = [
                            'label' => '<i class="fa fa-refresh"></i>' . __('Generate'),
                            'attr' => $indexAttr,
                            'url' => $generateUrl
                        ];
                    } else {
                        $indexAttr['title'] = $this->getMessage('ReportCardStatuses.date_closed');
                        $buttons['generate'] = [
                            'label' => '<i class="fa fa-refresh"></i>' . __('Generate'),
                            'attr' => $indexAttr,
                            'url' => 'javascript:void(0)'
                        ];
                    }
                }
            }
        }
        return $buttons;
    }
//POCOR-7998:end
}
