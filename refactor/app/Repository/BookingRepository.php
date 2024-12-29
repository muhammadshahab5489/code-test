<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(
                storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'),
                Logger::DEBUG)
        );
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);

        if (!$cuser) {
            return ['emergencyJobs' => [], 'normalJobs' => [], 'cuser' => null, 'usertype' => ''];
        }

        $usertype = $cuser->is('customer') ? 'customer' : ($cuser->is('translator') ? 'translator' : '');
        $jobs = [];

        if ($usertype === 'customer') {
            $jobs = $cuser->jobs()
                ->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback'])
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
        } elseif ($usertype === 'translator') {
            $jobs = Job::getTranslatorJobs($cuser->id, 'new')->pluck('jobs')->flatten();
        }

        $emergencyJobs = $jobs->filter(fn($job) => $job->immediate === 'yes');
        $normalJobs = $jobs->filter(fn($job) => $job->immediate !== 'yes')->map(function ($job) use ($user_id) {
            $job['usercheck'] = Job::checkParticularJob($user_id, $job);
            return $job;
        })->sortBy('due')->values();

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cuser' => $cuser,
            'usertype' => $usertype,
        ];
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $pagenum = $request->has('page') ? $request->get('page') : 1;
        $cuser = User::find($user_id);

        if (!$cuser) {
            return [
                'emergencyJobs' => [],
                'normalJobs' => [],
                'jobs' => [],
                'cuser' => null,
                'usertype' => '',
                'numpages' => 0,
                'pagenum' => $pagenum,
            ];
        }

        $emergencyJobs = [];
        $normalJobs = [];
        $usertype = '';

        if ($cuser->is('customer')) {
            $jobs = $cuser->jobs()
                ->with(
                    [
                        'user.userMeta',
                        'user.average',
                        'translatorJobRel.user.average',
                        'language',
                        'feedback',
                        'distance'
                    ]
                )
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);

            $usertype = 'customer';

            return [
                'emergencyJobs' => $emergencyJobs,
                'normalJobs' => [],
                'jobs' => $jobs,
                'cuser' => $cuser,
                'usertype' => $usertype,
                'numpages' => 0,
                'pagenum' => 0,
            ];
        }

        if ($cuser->is('translator')) {
            $jobsPaginator = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
            $totalJobs = $jobsPaginator->total();
            $numpages = ceil($totalJobs / 15);

            $usertype = 'translator';

            return [
                'emergencyJobs' => $emergencyJobs,
                'normalJobs' => $jobsPaginator,
                'jobs' => $jobsPaginator,
                'cuser' => $cuser,
                'usertype' => $usertype,
                'numpages' => $numpages,
                'pagenum' => $pagenum,
            ];
        }

        return [
            'emergencyJobs' => [],
            'normalJobs' => [],
            'jobs' => [],
            'cuser' => $cuser,
            'usertype' => $usertype,
            'numpages' => 0,
            'pagenum' => $pagenum,
        ];
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediateTime = 5;
        $response = [];
        $consumerType = $user->userMeta->consumer_type;

        if ($user->user_type !== env('CUSTOMER_ROLE_ID')) {
            return [
                'status' => 'fail',
                'message' => "Translator cannot create booking"
            ];
        }

        $requiredFields = [
            'from_language_id' => 'Du måste fylla in alla fält',
            'due_date' => 'Du måste fylla in alla fält',
            'due_time' => 'Du måste fylla in alla fält',
            'duration' => 'Du måste fylla in alla fält'
        ];

        foreach ($requiredFields as $field => $errorMessage) {
            if (empty($data[$field]) && $data['immediate'] === 'no') {
                return [
                    'status' => 'fail',
                    'message' => $errorMessage,
                    'field_name' => $field
                ];
            }
        }

        if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
            return [
                'status' => 'fail',
                'message' => "Du måste göra ett val här",
                'field_name' => "customer_phone_type"
            ];
        }

        $data['customer_phone_type'] = (isset($data['customer_phone_type'])) ? $data['customer_phone_type'] : 'no';
        $data['customer_physical_type'] = (isset($data['customer_physical_type'])) ? $data['customer_physical_type'] : 'no';
        $response['customer_physical_type'] = $data['customer_physical_type'];

        if ($data['immediate'] === 'yes') {
            $dueCarbon = Carbon::now()->addMinutes($immediateTime);
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type'] = 'immediate';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $due);

            if ($dueCarbon->isPast()) {
                return [
                    'status' => 'fail',
                    'message' => "Can't create booking in the past"
                ];
            }

            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
            $response['type'] = 'regular';
        }

        $data['gender'] = $this->getJobGender($data['job_for']);
        $data['certified'] = $this->getJobCertification($data['job_for']);

        $data['job_type'] = $this->getMatchJobType($consumerType);

        $data['b_created_at'] = now()->format('Y-m-d H:i:s');
        $data['will_expire_at'] = isset($due) ? TeHelper::willExpireAt($due, $data['b_created_at']) : null;
        $data['by_admin'] = (isset($data['by_admin'])) ? $data['by_admin'] : 'no';

        $job = $user->jobs()->create($data);
        $response['status'] = 'success';
        $response['id'] = $job->id;

        $data['job_for'] = $this->mapJobFor($job);
        $data['customer_town'] = $user->userMeta->city;
        $data['customer_type'] = $user->userMeta->customer_type;

        // Event::fire(new JobWasCreated($job, $data, '*'));
        // $this->sendNotificationToSuitableTranslators($job->id, $data, '*');

        return $response;
    }

    private function getMatchJobType($consumerType)
    {
        if ($consumerType === 'rwsconsumer') {
            $jobType = 'rws';
        } elseif ($consumerType === 'ngo') {
            $jobType = 'unpaid';
        } elseif ($consumerType === 'paid') {
            $jobType = 'paid';
        } else {
            $jobType = 'unknown';
        }

        return $jobType;
    }

    /**
     * @param array $jobFor
     * @return string|null
     */
    private function getJobGender(array $jobFor)
    {
        if (in_array('male', $jobFor)) return 'male';
        if (in_array('female', $jobFor)) return 'female';
        return null;
    }

    /**
     * @param array $jobFor
     * @return string
     */
    private function getJobCertification(array $jobFor)
    {
        if (in_array('normal', $jobFor) && in_array('certified', $jobFor)) return 'both';
        if (in_array('normal', $jobFor) && in_array('certified_in_law', $jobFor)) return 'n_law';
        if (in_array('normal', $jobFor) && in_array('certified_in_health', $jobFor)) return 'n_health';
        if (in_array('certified', $jobFor)) return 'yes';
        if (in_array('certified_in_law', $jobFor)) return 'law';
        if (in_array('certified_in_health', $jobFor)) return 'health';
        return 'normal';
    }

    /**
     * @param $job
     * @return array
     */
    private function mapJobFor($job)
    {
        $jobFor = [];
        if ($job->gender === 'male') $jobFor[] = 'Man';
        if ($job->gender === 'female') $jobFor[] = 'Kvinna';

        if ($job->certified === 'both') {
            $jobFor[] = 'normal';
            $jobFor[] = 'certified';
        } else if ($job->certified === 'yes') {
            $jobFor[] = 'certified';
        } else {
            $jobFor[] = $job->certified;
        }
        return $jobFor;
    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $userType = (isset($data['user_type'])) ? $data['user_type'] : null;
        $job = Job::findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';

        $user = $job->user()->first();

        if (!empty($data['address'])) {
            $job->address = $data['address'] ?: $user->userMeta->address;
            $job->instructions = $data['instructions'] ?: $user->userMeta->instructions;
            $job->town = $data['town'] ?: $user->userMeta->city;
        }

        $job->save();

        $email = $job->user_email ?: $user->email;
        $name = $user->name;

        $subject = "Vi har mottagit er tolkbokning. Bokningsnr: #{$job->id}";
        $sendData = ['user' => $user, 'job' => $job];

        $this->mailer->send($email, $name, $subject, 'emails.job-created', $sendData);

        $response = [
            'type' => $userType,
            'job' => $job,
            'status' => 'success',
        ];

        $eventData = $this->jobToData($job);
        Event::dispatch(new JobWasCreated($job, $eventData, '*'));

        return $response;
    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
        ];

        $due = Carbon::parse($job->due);
        $data['due_date'] = $due->toDateString();
        $data['due_time'] = $due->toTimeString();

        $data['job_for'] = [];

        if ($job->gender) {
            $data['job_for'][] = $job->gender === 'male' ? 'Man' : 'Kvinna';
        }

        if ($job->certified) {
            $certifiedMapping = [
                'both' => ['Godkänd tolk', 'Auktoriserad'],
                'yes' => ['Auktoriserad'],
                'n_health' => ['Sjukvårdstolk'],
                'law' => ['Rätttstolk'],
                'n_law' => ['Rätttstolk'],
            ];

            $data['job_for'] = array_merge(
                $data['job_for'],
                isset($certifiedMapping[$job->certified]) ? $certifiedMapping[$job->certified] : [$job->certified]
            );
        }

        return $data;
    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = array())
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if (!$job_detail) {
            return;
        }

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job_detail->end_at = $completeddate;
        $job_detail->status = 'completed';
        $job_detail->session_time = $interval;

        $user = $job_detail->user()->first();
        $email = !empty($job_detail->user_email) ? $job_detail->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job_detail->id;
        $session_explode = explode(':', $job_detail->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';

        $data = [
            'user' => $user,
            'job' => $job_detail,
            'session_time' => $session_time,
            'for_text' => 'faktura',
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
        $job_detail->save();
        $tr = $job_detail->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();

        if ($tr) {
            Event::fire(new SessionEnded(
                    $job_detail,
                    ($post_data['userid'] == $job_detail->user_id) ? $tr->user_id : $job_detail->user_id
                )
            );

            $user = $tr->user()->first();
            $email = $user->email;
            $name = $user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job_detail->id;

            $data = [
                'user' => $user,
                'job' => $job_detail,
                'session_time' => $session_time,
                'for_text' => 'lön',
            ];

            $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
            $tr->completed_at = $completeddate;
            $tr->completed_by = $post_data['userid'];
            $tr->save();
        }
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type = $this->getTranslatorType($translator_type);

        $languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $languages, $gender, $translator_level);

        $jobs = Job::whereIn('id', $job_ids)
            ->where(function ($query) use ($user_id) {
                $query->whereDoesntHave('customerPhoneType', function ($q) {
                    $q->where('customer_phone_type', 'no')->orWhereNull('customer_phone_type');
                })
                    ->where('customer_physical_type', 'yes')
                    ->whereDoesntHave('checkTowns', function ($q) use ($user_id) {
                        $q->where('job_user_id', '!=', $user_id); // Checking towns using a more optimized query
                    });
            })
            ->get();

        return TeHelper::convertJobIdsInObjs($jobs);
    }

    /**
     * @param $translator_type
     * @return string
     */
    private function getTranslatorType($translator_type)
    {
        switch ($translator_type) {
            case 'professional':
                $job_type = 'paid';
                break;
            case 'rwstranslator':
                $job_type = 'rws';
                break;
            case 'volunteer':
            default:
                $job_type = 'unpaid';
                break;
        }

        return $job_type;
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     * @return int
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $translators = User::where('user_type', '2')
            ->where('status', '1')
            ->where('id', '!=', $exclude_user_id)
            ->get();

        $translator_array = [];
        $delpay_translator_array = [];

        $immediate = $data['immediate'] == 'yes';
        $language = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $jobId = $job->id;
        $msg_contents = $immediate ?
            'Ny akutbokning för ' .
            $language . 'tolk ' .
            $data['duration'] .
            'min' :
            'Ny bokning för ' .
            $language . 'tolk ' .
            $data['duration'] . 'min ' .
            $data['due'];

        $msg_text = [
            "en" => $msg_contents
        ];

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(
                storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'),
                Logger::DEBUG
            )
        );
        $logger->pushHandler(new FirePHPHandler());

        foreach ($translators as $oneUser) {
            if (!$this->isNeedToSendPush($oneUser->id)) continue;

            $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
            if ($immediate && $not_get_emergency == 'yes') continue;
            $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id);
            foreach ($jobs as $oneJob) {
                if ($jobId == $oneJob->id) {
                    $job_for_translator = Job::assignedToPaticularTranslator($oneUser->id, $oneJob->id);
                    if ($job_for_translator == 'SpecificJob') {
                        $job_checker = Job::checkParticularJob($oneUser->id, $oneJob);
                        if ($job_checker != 'userCanNotAcceptJob') {
                            if ($this->isNeedToDelayPush($oneUser->id)) {
                                $delpay_translator_array[] = $oneUser;
                            } else {
                                $translator_array[] = $oneUser;
                            }
                        }
                    }
                }
            }
        }

        $logger->addInfo('Push send for job ' . $jobId, [$translator_array, $delpay_translator_array, $msg_text, $data]);

        $this->sendPushNotificationToSpecificUsers($translator_array, $jobId, $data, $msg_text, false); // Send immediate push
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $jobId, $data, $msg_text, true); // Send delayed push

        return count($translators);
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = (isset($job->city)) ? $job->city : $jobPosterMeta->city;

        $templates = [
            'phone' => trans('sms.phone_job', [
                'date' => $date,
                'time' => $time,
                'duration' => $duration,
                'jobId' => $jobId
            ]),
            'physical' => trans('sms.physical_job', [
                'date' => $date,
                'time' => $time,
                'town' => $city,
                'duration' => $duration,
                'jobId' => $jobId
            ])
        ];

        $message = ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no')
            ? $templates['physical']
            : $templates['phone'];

        Log::info("Prepared message for job {$jobId}: {$message}");

        if (empty($translators)) {
            return 0;  // Early exit if no translators
        }

        $translatorCount = 0;
        foreach ($translators as $translator) {
            // Send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info("Send SMS to {$translator->email} ({$translator->mobile}), status: " . print_r($status, true));

            $translatorCount++;
        }

        return $translatorCount;
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        if ($not_get_nighttime == 'yes') return true;
        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        if ($not_get_notification == 'yes') return false;
        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     * @return
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        try {
            $logger = new Logger('push_logger');
            $logFile = storage_path('logs/push/laravel-' . date('Y-m-d') . '.log');
            $logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
            $logger->pushHandler(new FirePHPHandler());
            $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);
            $envPrefix = (env('APP_ENV') == 'prod') ? 'prod' : 'dev';
            $onesignalAppID = config("app.${envPrefix}OnesignalAppID");
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config("app.${envPrefix}OnesignalApiKey"));
            $user_tags = $this->getUserTagsStringFromArray($users);

            $data['job_id'] = $job_id;
            $sounds = [
                'normal_booking' => ['android' => 'normal_booking', 'ios' => 'normal_booking.mp3'],
                'emergency_booking' => ['android' => 'emergency_booking', 'ios' => 'emergency_booking.mp3']
            ];

            $notificationType = $data['notification_type'];
            $isImmediate = $data['immediate'] == 'yes' ? 'emergency_booking' : 'normal_booking';

            $soundConfig = $sounds[$isImmediate];

            $fields = [
                'app_id' => $onesignalAppID,
                'tags' => json_decode($user_tags),
                'data' => $data,
                'title' => ['en' => 'DigitalTolk'],
                'contents' => $msg_text,
                'ios_badgeType' => 'Increase',
                'ios_badgeCount' => 1,
                'android_sound' => $soundConfig['android'],
                'ios_sound' => $soundConfig['ios'],
            ];

            if ($is_need_delay) {
                $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
            }

            $fieldsJson = json_encode($fields);
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => "https://onesignal.com/api/v1/notifications",
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', $onesignalRestAuthKey],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $fieldsJson,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $response = curl_exec($ch);
            $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);

            curl_close($ch);
        } catch (\Exception $exception) {
            return response($exception->getMessage());
        }
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $job_type = $job->job_type;
        $translator_type = $this->getTranslatorType($job_type);

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];

        if (!empty($job->certified)) {
            switch ($job->certified) {
                case 'yes':
                case 'both':
                    $translator_level = [
                        'Certified',
                        'Certified with specialisation in law',
                        'Certified with specialisation in health care'
                    ];
                    break;
                case 'law':
                case 'n_law':
                    $translator_level = ['Certified with specialisation in law'];
                    break;
                case 'health':
                case 'n_health':
                    $translator_level = ['Certified with specialisation in health care'];
                    break;
                case 'normal':
                case 'both':
                    $translator_level = ['Layman', 'Read Translation courses'];
                    break;
                case null:
                    $translator_level = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'];
                    break;
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $blacklist);

        return $users;
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);
        $current_translator = $job->translatorJobRel->whereNull('cancel_at')->first();

        if (!$current_translator) {
            $current_translator = $job->translatorJobRel->whereNotNull('completed_at')->first();
        }

        $log_data = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id !== $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id']),
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];

        $this->logger->addInfo('USER #' . $cuser->id . ' (' . $cuser->name . ') updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data: ', $log_data);
        $job->save();

        if ($job->due > Carbon::now()) {
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification(
                $job,
                $current_translator,
                $changeTranslator['new_translator']
            );
            if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
        } else {
            return ['Updated'];
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        if ($old_status != $data['status']) {
            $statusChangeFunctions = [
                'timedout' => 'changeTimedoutStatus',
                'completed' => 'changeCompletedStatus',
                'started' => 'changeStartedStatus',
                'pending' => 'changePendingStatus',
                'withdrawafter24' => 'changeWithdrawafter24Status',
                'assigned' => 'changeAssignedStatus',
            ];

            if (isset($statusChangeFunctions[$old_status])) {
                $changeMethod = $statusChangeFunctions[$old_status];
                $statusChanged = $this->$changeMethod($job, $data, $changedTranslator ?? null);
            } else {
                $statusChanged = false;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status'],
                ];
                return ['statusChanged' => true, 'log_data' => $log_data];
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = ['user' => $user, 'job' => $job];

        if ($data['status'] == 'pending') {
            // Update job status and timestamps
            $job->created_at = now();
            $job->emailsent = $job->emailsenttovirpal = 0;
            $job->save();

            // Prepare and send email notification
            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            // Send Push notification to all suitable translators
            $this->sendNotificationTranslator($job, $this->jobToData($job), '*');
            return true;

        } elseif ($changedTranslator) {
            // Save and send confirmation email when translator is changed
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['status'] == 'timedout' && empty($data['admin_comments'])) {
            return false; // Return early if admin comments are required for 'timedout'
        }

        if ($data['status'] == 'timedout') {
            $job->admin_comments = $data['admin_comments'];
        }

        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];

        if (empty($data['admin_comments'])) return false;
        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] == 'completed') {
            if (empty($data['sesion_time'])) return false;

            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = now();
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            $user = $job->user()->first();
            $email = $job->user_email ?: $user->email;
            $name = $user->name;
            $dataEmail = [
                'user' => $user,
                'job' => $job,
                'session_time' => $session_time,
                'for_text' => 'faktura',
            ];
            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $translator = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
            if ($translator) {
                $email = $translator->user->email;
                $name = $translator->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $dataEmail = [
                    'user' => $translator->user,
                    'job' => $job,
                    'session_time' => $session_time,
                    'for_text' => 'lön',
                ];
                $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
            }
        }

        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];

        if ($data['status'] == 'timedout' && empty($data['admin_comments'])) {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];

        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $dataEmail = ['user' => $user, 'job' => $job];
        if ($data['status'] == 'assigned' && $changedTranslator) {
            $job->save();
            $job_data = $this->jobToData($job);

            // Send email to customer
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            // Send email to translator
            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            // Send session reminders
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

            $job->save();
            return true;
        }
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->logger->pushHandler(new StreamHandler(
                storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'),
                Logger::DEBUG
            )
        );
        $this->logger->pushHandler(new FirePHPHandler());

        $data = ['notification_type' => 'session_start_remind'];
        list($dueDate, $dueTime) = explode(' ', $due);

        $msg_text = [
            "en" => sprintf(
                'Detta är en påminnelse om att du har en %stolkning (%s) kl %s på %s som vara i %s min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!',
                $language,
                $job->customer_physical_type == 'yes' ? 'på plats i ' . $job->town : 'telefon',
                $dueTime,
                $dueDate,
                $duration
            )
        ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $this->bookingRepository->sendPushNotificationToSpecificUsers(
                [$user],
                $job->id,
                $data,
                $msg_text,
                $this->bookingRepository->isNeedToDelayPush($user->id)
            );
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout']) && !empty($data['admin_comments'])) {
            $job->status = $data['status'];
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        $status = $data['status'];
        $adminCommentsEmpty = empty($data['admin_comments']);

        if (in_array($status, ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $status;
            if ($adminCommentsEmpty && $status === 'timedout') {
                return false;
            }

            $job->admin_comments = $data['admin_comments'];
            if (in_array($status, ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();
                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $dataEmail = ['user' => $user, 'job' => $job];
                $this->mailer->send($email, $user->name, 'Information om avslutad tolkning för bokningsnummer #' . $job->id, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
                $translator = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();
                if ($translator) {
                    $email = $translator->user->email;
                    $this->mailer->send($email, $translator->user->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job->id, 'emails.job-cancel-translator', $dataEmail);
                }
            }

            $job->save();
            return true;
        }

        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;
        $log_data = [];

        $translatorId = (isset($data['translator'])) ? $data['translator'] : null;
        $translatorEmail = (isset($data['translator_email'])) ? $data['translator_email'] : '';

        if (!is_null($current_translator) || (!empty($translatorId) || !empty($translatorEmail))) {
            if (!empty($translatorEmail)) {
                $translator = User::where('email', $translatorEmail)->first();
                $translatorId = $translator ? $translator->id : null;
            }
            if (!is_null($current_translator) &&
                ($current_translator->user_id != $translatorId ||
                    !empty($translatorEmail)
                )
            ) {
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $translatorId;
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();

                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email,
                ];

                $translatorChanged = true;
            } elseif (is_null($current_translator) && !empty($translatorId)) {
                $new_translator = Translator::create(['user_id' => $translatorId, 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email,
                ];

                $translatorChanged = true;
            }

            if ($translatorChanged) {
                return [
                    'translatorChanged' => $translatorChanged,
                    'new_translator' => $new_translator,
                    'log_data' => $log_data
                ];
            }

            return ['translatorChanged' => $translatorChanged];
        }
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];

    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = ['user' => null, 'job' => $job];  // Initialize with a placeholder for 'user'

        $email = !empty($job->user_email) ? $job->user_email : $job->user->email;
        $data['user'] = $job->user;
        $this->mailer->send($email, $job->user->name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            $data['user'] = $current_translator->user;
            $this->mailer->send(
                $current_translator->user->email,
                $current_translator->user->name,
                $subject,
                'emails.job-changed-translator-old-translator',
                $data
            );
        }

        $data['user'] = $new_translator->user;
        $this->mailer->send(
            $new_translator->user->email,
            $new_translator->user->name,
            $subject,
            'emails.job-changed-translator-new-translator',
            $data
        );
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
        $data = ['job' => $job, 'old_time' => $old_time];

        $email = !empty($job->user_email) ? $job->user_email : $job->user->email;
        $data['user'] = $job->user;
        $this->mailer->send($email, $job->user->name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data['user'] = $translator;
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
        $data = [
            'job' => $job,
            'old_lang' => $old_lang
        ];

        $email = !empty($job->user_email) ? $job->user_email : $job->user->email;
        $data['user'] = $job->user;
        $this->mailer->send($email, $job->user->name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data['user'] = $translator;
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-lang', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = [
            'notification_type' => 'job_expired'
        ];

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => "Tyvärr har ingen tolk accepterat er bokning: ({$language}, {$job->duration}min, {$job->due}). Vänligen pröva boka om tiden."
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToSpecificUsers(
                [$user],
                $job->id,
                $data,
                $msg_text,
                $this->isNeedToDelayPush($user->id)
            );
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();

        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $user_meta->city,
            'customer_type' => $user_meta->customer_type,
            'due_date' => explode(" ", $job->due)[0],
            'due_time' => explode(" ", $job->due)[1],
            'job_for' => []
        ];

        if ($job->gender) {
            $data['job_for'][] = $job->gender == 'male' ? 'Man' : 'Kvinna';
        }

        if ($job->certified) {
            $certified = $job->certified;
            if ($certified == 'both') {
                $data['job_for'] = array_merge($data['job_for'], ['normal', 'certified']);
            } else {
                $data['job_for'][] = $certified == 'yes' ? 'certified' : $certified;
            }
        }

        $this->sendNotificationTranslator($job, $data, '*');
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        else
            $msg_text = array(
                "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers(
                $users_array,
                $job->id,
                $data,
                $msg_text,
                $this->bookingRepository->isNeedToDelayPush($user->id)
            );
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';
        return $user_tags;
    }

    /**
     * @param $data
     * @param $user
     * @return array
     */
    public function acceptJob($data, $user)
    {
        $cuser = $user;
        $job = Job::findOrFail($data['job_id']);

        if (Job::isTranslatorAlreadyBooked($job->id, $cuser->id, $job->due)) {
            return [
                'status' => 'fail',
                'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'
            ];
        }

        if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job->id)) {
            $job->status = 'assigned';
            $job->save();

            $user = $job->user()->first();
            $mailer = new AppMailer();

            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

            $data = [
                'user' => $user,
                'job' => $job
            ];
            $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

            /*@todo add flash message here*/

            $jobs = $this->getPotentialJobs($cuser);
            return [
                'list' => json_encode(['jobs' => $jobs, 'job' => $job], true),
                'status' => 'success'
            ];
        }
    }

    /**
     * @param $job_id
     * @param $cuser
     * @return array
     */
    public function acceptJobWithId($job_id, $cuser)
    {
        $job = Job::findOrFail($job_id);
        $response = [];

        if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            return [
                'status' => 'fail',
                'message' => 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning'
            ];
        }

        if ($job->status != 'pending' || !Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            return [
                'status' => 'fail',
                'message' => 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning'
            ];
        }

        $job->status = 'assigned';
        $job->save();

        $user = $job->user()->first();
        $mailer = new AppMailer();

        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job' => $job
        ];
        $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
        ];
        $data = ['notification_type' => 'job_accepted'];

        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToSpecificUsers([$user], $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }

        $response['status'] = 'success';
        $response['list']['job'] = $job;
        $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;

        return $response;
    }

    /**
     * @param $data
     * @param $user
     * @return array
     */
    public function cancelJobAjax($data, $user)
    {
        $response = [];
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $now = Carbon::now();
        $jobDue = $job->due;
        $withdrawalTime = $now->diffInHours($jobDue);
        $isCustomer = $cuser->is('customer');

        if ($isCustomer) {
            $job->withdraw_at = $now;
            $job->status = $withdrawalTime >= 24 ? 'withdrawbefore24' : 'withdrawafter24';
            $job->save();

            Event::fire(new JobWasCanceled($job));

            $response['status'] = 'success';
            $response['jobstatus'] = 'success';

            if ($translator) {
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                ];

                $this->sendPushNotificationToSpecificUsers([$translator], $job_id, ['notification_type' => 'job_cancelled'], $msg_text, $this->isNeedToDelayPush($translator->id));
            }
        } else {
            if ($jobDue->diffInHours($now) > 24) {
                $customer = $job->user()->first();

                if ($customer) {
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = [
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    ];

                    $this->sendPushNotificationToSpecificUsers([$customer], $job_id, ['notification_type' => 'job_cancelled'], $msg_text, $this->isNeedToDelayPush($customer->id));
                }

                $job->status = 'pending';
                $job->created_at = now();
                $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
                $job->save();

                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);
                $this->sendNotificationTranslator($job, $data, $translator->id); // send Push all suitable translators

                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }

        return $response;
    }

    /**
     * @param $cuser
     * @return mixed
     */
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $translator_type = $cuser_meta->translator_type;
        $job_type = $this->getTranslatorType($translator_type);

        $languages = UserLanguages::where('user_id', $cuser->id)->pluck('lang_id');
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $languages, $gender, $translator_level);

        $job_ids = $job_ids->filter(function ($job) use ($cuser, $cuser_meta) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);

            if ($job->specific_job === 'SpecificJob' && $job->check_particular_job === 'userCanNotAcceptJob') {
                return false;
            }

            if (($job->customer_phone_type == 'no' || empty($job->customer_phone_type))
                && $job->customer_physical_type == 'yes'
                && !Job::checkTowns($jobuserid, $cuser->id)) {
                return false;
            }

            return true;
        });

        return $job_ids;
    }

    /**
     * @param $post_data
     * @return array
     */
    public function endJob($post_data)
    {
        $completeddate = now();
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if ($job_detail->status !== 'started') {
            return ['status' => 'success'];
        }

        $start = Carbon::parse($job_detail->due);
        $end = Carbon::parse($completeddate);
        $session_time = $end->diff($start)->format('%h:%i:%s');

        $job_detail->update([
            'end_at' => $completeddate,
            'status' => 'completed',
            'session_time' => $session_time
        ]);

        $user = $job_detail->user;
        $email = $job_detail->user_email ?? $user->email;
        $session_time_formatted = Carbon::parse($session_time)->format('H \h i \m\i\n');
        $data = [
            'user' => $user,
            'job' => $job_detail,
            'session_time' => $session_time_formatted,
            'for_text' => 'faktura'
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $user->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job_detail->id, 'emails.session-ended', $data);

        $tr = $job_detail->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();

        if ($tr) {
            $translator = $tr->user;
            $data['for_text'] = 'lön';
            $mailer->send($translator->email, $translator->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job_detail->id, 'emails.session-ended', $data);

            $tr->update([
                'completed_at' => $completeddate,
                'completed_by' => $post_data['user_id']
            ]);
        }
        Event::fire(new SessionEnded($job_detail, ($post_data['user_id'] == $job_detail->user_id) ? $tr->user_id : $job_detail->user_id));

        return ['status' => 'success'];
    }

    /**
     * @param $post_data
     * @return array
     */
    public function customerNotCall($post_data)
    {
        $completeddate = now();
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if (!$job_detail) {
            return ['status' => 'fail', 'message' => 'Job not found.'];
        }

        $start = Carbon::parse($job_detail->due);
        $end = Carbon::parse($completeddate);
        $session_time = $end->diff($start)->format('%h:%i:%s');

        $job_detail->update([
            'end_at' => $completeddate,
            'status' => 'not_carried_out_customer',
        ]);

        $translatorJobRel = $job_detail->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();

        if ($translatorJobRel) {
            $translatorJobRel->update([
                'completed_at' => $completeddate,
                'completed_by' => $translatorJobRel->user_id,
            ]);
        }

        $response['status'] = 'success';
        return $response;
    }

    /**
     * @param Request $request
     * @param null $limit
     * @return mixed
     */
    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        $allJobs = Job::query();

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs->when(
                isset($requestdata['feedback']) && $requestdata['feedback'] !== 'false',
                function ($query) {
                    $query->where('ignore_feedback', '0')
                        ->whereHas('feedback', function ($q) {
                            $q->where('rating', '<=', 3);
                        });
                }
            )
                ->when(
                    isset($requestdata['id']),
                    function ($query) use ($requestdata) {
                        $query->whereIn('id', (array)$requestdata['id']);
                    }
                )
                ->when(
                    !empty($requestdata['lang']),
                    function ($query) use ($requestdata) {
                        $query->whereIn('from_language_id', $requestdata['lang']);
                    }
                )
                ->when(
                    !empty($requestdata['status']),
                    function ($query) use ($requestdata) {
                        $query->whereIn('status', $requestdata['status']);
                    }
                )
                ->when(
                    !empty($requestdata['expired_at']),
                    function ($query) use ($requestdata) {
                        $query->where('expired_at', '>=', $requestdata['expired_at']);
                    }
                )
                ->when(
                    !empty($requestdata['will_expire_at']),
                    function ($query) use ($requestdata) {
                        $query->where('will_expire_at', '>=', $requestdata['will_expire_at']);
                    }
                )
                ->when(
                    !empty($requestdata['customer_email']),
                    function ($query) use ($requestdata) {
                        $users = DB::table('users')->whereIn('email', (array)$requestdata['customer_email'])->get();
                        if ($users->isNotEmpty()) {
                            $query->whereIn('user_id', $users->pluck('id'));
                        }
                    }
                )
                ->when(
                    !empty($requestdata['translator_email']),
                    function ($query) use ($requestdata) {
                        $users = DB::table('users')->whereIn('email', (array)$requestdata['translator_email'])->get();
                        if ($users->isNotEmpty()) {
                            $allJobIDs = DB::table('translator_job_rel')
                                ->whereNull('cancel_at')
                                ->whereIn('user_id', $users->pluck('id'))
                                ->pluck('job_id');
                            $query->whereIn('id', $allJobIDs);
                        }
                    }
                )
                ->when(
                    !empty($requestdata['filter_timetype']),
                    function ($query) use ($requestdata) {
                        $timeField = $requestdata['filter_timetype'] === 'created' ? 'created_at' : 'due';
                        if (!empty($requestdata['from'])) {
                            $query->where($timeField, '>=', $requestdata['from']);
                        }
                        if (!empty($requestdata['to'])) {
                            $query->where($timeField, '<=', $requestdata['to'] . ' 23:59:00');
                        }
                        $query->orderBy($timeField, 'desc');
                    }
                )
                ->when(
                    !empty($requestdata['job_type']),
                    function ($query) use ($requestdata) {
                        $query->whereIn('job_type', $requestdata['job_type']);
                    }
                )
                ->when(
                    isset($requestdata['physical']),
                    function ($query) use ($requestdata) {
                        $query->where('customer_physical_type', $requestdata['physical'])
                            ->where('ignore_physical', 0);
                    }
                )
                ->when(
                    isset($requestdata['phone']),
                    function ($query) use ($requestdata) {
                        $query->where('customer_phone_type', $requestdata['phone']);
                        if (isset($requestdata['physical'])) {
                            $query->where('ignore_physical_phone', 0);
                        }
                    }
                )
                ->when(
                    isset($requestdata['flagged']),
                    function ($query) use ($requestdata) {
                        $query->where('flagged', $requestdata['flagged'])
                            ->where('ignore_flagged', 0);
                    }
                )
                ->when(
                    isset($requestdata['distance']) && $requestdata['distance'] === 'empty',
                    function ($query) {
                        $query->whereDoesntHave('distance');
                    }
                )
                ->when(
                    isset($requestdata['salary']) && $requestdata['salary'] === 'yes',
                    function ($query) {
                        $query->whereDoesntHave('user.salaries');
                    }
                )
                ->when(
                    !empty($requestdata['consumer_type']),
                    function ($query) use ($requestdata) {
                        $query->whereHas('user.userMeta', function ($q) use ($requestdata) {
                            $q->where('consumer_type', $requestdata['consumer_type']);
                        });
                    }
                )
                ->when(
                    isset($requestdata['booking_type']),
                    function ($query) use ($requestdata) {
                        $bookingType = $requestdata['booking_type'] === 'physical' ? 'customer_physical_type' : 'customer_phone_type';
                        $query->where($bookingType, 'yes');
                    }
                );

            $allJobs = ($limit === 'all') ? $allJobs->get() : $allJobs->paginate(15);
        } else {
            $allJobs->where('job_type', $consumer_type === 'RWS' ? 'rws' : 'unpaid')
                ->when(
                    isset($requestdata['id']),
                    function ($query) use ($requestdata) {
                        $query->where('id', $requestdata['id']);
                    }
                )
                ->when(
                    !empty($requestdata['feedback']) && $requestdata['feedback'] !== 'false',
                    function ($query) {
                        $query->where('ignore_feedback', '0')
                            ->whereHas('feedback', function ($q) {
                                $q->where('rating', '<=', 3);
                            });
                    }
                )
                ->when(
                    !empty($requestdata['lang']),
                    function ($query) use ($requestdata) {
                        $query->whereIn('from_language_id', $requestdata['lang']);
                    }
                )
                ->when(
                    !empty($requestdata['status']),
                    function ($query) use ($requestdata) {
                        $query->whereIn('status', $requestdata['status']);
                    }
                )
                ->when(
                    !empty($requestdata['job_type']),
                    function ($query) use ($requestdata) {
                        $query->whereIn('job_type', $requestdata['job_type']);
                    }
                )
                ->when(
                    !empty($requestdata['customer_email']),
                    function ($query) use ($requestdata) {
                        $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                        if ($user) {
                            $query->where('user_id', $user->id);
                        }
                    }
                )
                ->when(
                    !empty($requestdata['filter_timetype']),
                    function ($query) use ($requestdata) {
                        $timeField = $requestdata['filter_timetype'] === 'created' ? 'created_at' : 'due';
                        if (!empty($requestdata['from'])) {
                            $query->where($timeField, '>=', $requestdata['from']);
                        }
                        if (!empty($requestdata['to'])) {
                            $query->where($timeField, '<=', $requestdata['to'] . ' 23:59:00');
                        }
                        $query->orderBy($timeField, 'desc');
                    }
                );

            $allJobs = ($limit === 'all') ? $allJobs->get() : $allJobs->paginate(15);
        }

        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
        return $allJobs;
    }

    /**
     * @return array
     */
    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diffValue = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
                if ($diffValue >= $job->duration && $diffValue >= $job->duration * 2) {
                    $sesJobs[] = $job;
                    $jobId[] = $job->id;
                }
            }
        }

        $languages = Language::active()->orderBy('language')->get();
        $requestdata = Request::all();
        $cuser = '';
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email')->toArray();
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email')->toArray();

        $allJobs = Job::query()
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->whereIn('jobs.id', $jobId)
            ->where('jobs.ignore', 0);

        if ($cuser && $cuser->is('superadmin')) {

            // Filter conditions
            $filters = [
                'lang' => function ($query) use ($requestdata) {
                    if (!empty($requestdata['lang'])) {
                        $query->whereIn('jobs.from_language_id', (array)$requestdata['lang']);
                    }
                },
                'status' => function ($query) use ($requestdata) {
                    if (!empty($requestdata['status'])) {
                        $query->whereIn('jobs.status', (array)$requestdata['status']);
                    }
                },
                'customer_email' => function ($query) use ($requestdata) {
                    if (!empty($requestdata['customer_email'])) {
                        $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                        if ($user) {
                            $query->where('jobs.user_id', $user->id);
                        }
                    }
                },
                'translator_email' => function ($query) use ($requestdata) {
                    if (!empty($requestdata['translator_email'])) {
                        $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                        if ($user) {
                            $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
                            $query->whereIn('jobs.id', $allJobIDs);
                        }
                    }
                },
                'filter_timetype' => function ($query) use ($requestdata) {
                    if (!empty($requestdata['filter_timetype']) && in_array($requestdata['filter_timetype'], ['created', 'due'])) {
                        $dateField = $requestdata['filter_timetype'] == 'created' ? 'created_at' : 'due';
                        if (!empty($requestdata['from'])) {
                            $query->where('jobs.' . $dateField, '>=', $requestdata['from']);
                        }
                        if (!empty($requestdata['to'])) {
                            $to = $requestdata['to'] . " 23:59:00";
                            $query->where('jobs.' . $dateField, '<=', $to);
                        }
                        $query->orderBy('jobs.' . $dateField, 'desc');
                    }
                },
                'job_type' => function ($query) use ($requestdata) {
                    if (!empty($requestdata['job_type'])) {
                        $query->whereIn('jobs.job_type', (array)$requestdata['job_type']);
                    }
                },
            ];

            // Apply filters dynamically
            foreach ($filters as $filter => $closure) {
                $closure($allJobs);
            }

            // Final query
            $allJobs->select('jobs.*', 'languages.language')
                ->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        } else {
            // Non-superadmin filtering logic
            $allJobs->where('jobs.job_type', $cuser->consumer_type === 'RWS' ? 'rws' : 'unpaid');
            $allJobs->select('jobs.*', 'languages.language')->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata
        ];

    }

    /**
     * @return array
     */
    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);
        return ['throttles' => $throttles];
    }


    /**
     * @return array
     */
    public function bookingExpireNoAccepted()
    {
        $languages = Language::active()->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email')->toArray();
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email')->toArray();

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        $allJobs = Job::join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->where('jobs.ignore_expired', 0)
            ->where('jobs.status', 'pending')
            ->where('jobs.due', '>=', Carbon::now());

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $filters = [
                'lang' => function ($query) use ($requestdata) {
                    if (!empty($requestdata['lang'])) {
                        $query->whereIn('jobs.from_language_id', (array)$requestdata['lang']);
                    }
                },
                'status' => function ($query) use ($requestdata) {
                    if (!empty($requestdata['status'])) {
                        $query->whereIn('jobs.status', (array)$requestdata['status']);
                    }
                },
                'customer_email' => function ($query) use ($requestdata) {
                    if (!empty($requestdata['customer_email'])) {
                        $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                        if ($user) {
                            $query->where('jobs.user_id', $user->id);
                        }
                    }
                },
                'translator_email' => function ($query) use ($requestdata) {
                    if (!empty($requestdata['translator_email'])) {
                        $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                        if ($user) {
                            $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
                            $query->whereIn('jobs.id', $allJobIDs);
                        }
                    }
                },
                'filter_timetype' => function ($query) use ($requestdata) {
                    if (!empty($requestdata['filter_timetype'])) {
                        $dateField = $requestdata['filter_timetype'] == "created" ? 'created_at' : 'due';
                        if (!empty($requestdata['from'])) {
                            $query->where('jobs.' . $dateField, '>=', $requestdata['from']);
                        }
                        if (!empty($requestdata['to'])) {
                            $to = $requestdata['to'] . " 23:59:00";
                            $query->where('jobs.' . $dateField, '<=', $to);
                        }
                        $query->orderBy('jobs.' . $dateField, 'desc');
                    }
                },
                'job_type' => function ($query) use ($requestdata) {
                    if (!empty($requestdata['job_type'])) {
                        $query->whereIn('jobs.job_type', (array)$requestdata['job_type']);
                    }
                },
            ];

            foreach ($filters as $filter => $closure) {
                $closure($allJobs);
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->orderBy('jobs.created_at', 'desc');

            $allJobs = $allJobs->paginate(15);
        }

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata
        ];
    }

    /**
     * @param $id
     * @return array
     */
    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    /**
     * @param $id
     * @return array
     */
    private function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    /**
     * @param $request
     * @return array
     */
    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];
        $job = Job::find($jobid);

        if (!$job) {
            return ["Job not found!"];
        }

        $currentTime = Carbon::now();
        $willExpireAt = TeHelper::willExpireAt($job->due, $currentTime);

        $data = [
            'created_at' => $currentTime,
            'updated_at' => $currentTime,
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => $currentTime,
            'will_expire_at' => $willExpireAt
        ];

        $dataReopen = [
            'status' => 'pending',
            'created_at' => $currentTime,
            'will_expire_at' => $willExpireAt,
        ];

        if ($job->status !== 'timedout') {
            $affectedRows = Job::where('id', $jobid)->update($dataReopen);
            $newJobId = $jobid;
        } else {
            $job->status = 'pending';
            $job->created_at = $currentTime;
            $job->updated_at = $currentTime;
            $job->will_expire_at = $willExpireAt;
            $job->cust_16_hour_email = 0;
            $job->cust_48_hour_email = 0;
            $job->admin_comments = 'This booking is a reopening of booking #' . $jobid;

            $newJob = Job::create($job->toArray());
            $newJobId = $newJob->id;
        }

        Translator::where('job_id', $jobid)->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);

        if (isset($affectedRows) || isset($newJobId)) {
            $this->sendNotificationByAdminCancelJob($newJobId);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param int $time
     * @param string $format
     * @return string
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        }

        if ($time == 60) {
            return '1h';
        }

        return floor($time / 60) . 'h ' . ($time % 60) . 'min';
    }
}