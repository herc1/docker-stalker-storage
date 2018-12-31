<?php

namespace Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as Response;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\FormFactoryInterface as FormFactoryInterface;
use Imagine\Image\Box;
use Stalker\Lib\Core\Config;

class NewVideoClubController extends \Controller\BaseStalkerController {

    public function __construct(Application $app) {
        parent::__construct($app, __CLASS__);
        $this->logoHost = $this->baseHost . Config::getSafe('portal_url', '/stalker_portal/') . "misc/logos";
        $this->logoDir = str_replace('/admin', '', $this->baseDir) . "/misc/logos";
        $this->app['error_local'] = array();
        $this->app['baseHost'] = $this->baseHost;

        $this->app['videoType'] = array(
            array('val' => 0, 'title' => $this->setLocalization('Uniserial')),
            array('val' => 1, 'title' => $this->setLocalization('Serial'))
        );
    }
    
    // ------------------- action method ---------------------------------------

    public function index() {
        
        if (empty($this->app['action_alias'])) {
            return $this->app->redirect($this->app['controller_alias'] . '/video-list');
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function video_list() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        $allYears = $this->db->getAllFromTable('video', 'year', 'year');

        $this->app['allYears'] = array_filter(array_map(function($val){
            if ((int)$val['year'] >= 1895) {
                return array('id'=>$val['year'], 'title'=>$val['year']);
            }
            return FALSE;
        }, $allYears));
        
        $this->app['allGenre'] =  $this->prepareNewGenresListIds($this->db->getVideoCategories());

        $this->app['allStatus'] = array(
            array('id' => 1, 'title' => $this->setLocalization('Unpublished')),
            array('id' => 2, 'title' => $this->setLocalization('Published')),
            array('id' => 3, 'title' => $this->setLocalization('Scheduled'))
        );

        $this->app['allModerators'] = $this->db->getAllAdmins();
        
        $attribute = $this->getVideoListDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        $this->getVideoListFilters();

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function add_video() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        $this->prepareFormVideoCategories();

        $attribute = $this->getVideoFilesDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        $form = $this->buildForm();
        
        if ($this->saveVideoData($form)) {
            $url = $this->workURL . '/' . $this->app['controller_alias'] . '/edit-video?id=' . $this->oneVideo['id'];
            return $this->app->redirect($url);
        }
        $this->app['form'] = $form->createView();
        $data = $form->getData();
        if (!empty($data['cover_id'])) {
            $this->app['curr_cover_dir'] = $this->baseHost . Config::getSafe('portal_url', '/stalker_portal/') . "screenshots/" . ceil(intval(str_replace('.jpg', '', $data['cover_id'])) / 100);
        } else {
            $this->app['curr_cover_dir'] = '';
        }

        $allLanguages = $this->getLanguageCodesEN();
        if (is_array($allLanguages)) {
            asort($allLanguages);
        } else {
            $allLanguages = array();
        }
        $this->app['allLanguages'] = $allLanguages;

        $this->app['videoEdit'] = FALSE;

        $this->app['breadcrumbs']->addItem($this->setLocalization('Movie list'), $this->app['controller_alias'] . '/video-list');
        $this->app['breadcrumbs']->addItem($this->setLocalization('Add movie'));

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    public function edit_video() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        if ($this->method == 'POST' && !empty($this->postData['form']['id'])) {
            $id = $this->postData['form']['id'];
        } else if ($this->method == 'GET' && !empty($this->data['id'])) {
            $id = $this->data['id'];
        } else {
            return $this->app->redirect('add-video');
        }
        $this->oneVideo = $this->db->getVideoById($id);
        if (empty($this->oneVideo['id'])){
            $this->oneVideo['id'] = $id;
        }
        $images = $this->db->getScreenshotData($this->oneVideo['id'], 'all');
        $base_images_path = $this->baseHost . Config::getSafe('portal_url', '/stalker_portal/') . "screenshots/";

        if (!empty($images)) {
            $this->app['videoImages'] = array_map(function($row) use ($base_images_path) {
                $ext = end(explode('.', $row['name']));
                $row['name'] = $row['id'] . ((int)$row['video_episodes'] > 0 ? "_$row[video_episodes]" : '') . ".$ext";
                $row['curr_cover_dir'] = $base_images_path . ceil(intval($row['id']) / 100);
                return $row;
            }, array_combine($this->getFieldFromArray($images, 'video_episodes'), $images));
        } else {
            $this->app['videoImages'] = array();
        }
        $this->oneVideo['cover_id'] = !empty($this->app['videoImages'][0]) && !empty($this->app['videoImages'][0]['id']) && (int)$this->app['videoImages'][0]['video_episodes'] == 0 ? $this->app['videoImages'][0]['id'] : '';
        if(!empty($this->oneVideo['cover_id'])){
            $this->app['curr_cover_dir'] = $base_images_path . ceil(intval($this->oneVideo['cover_id']) / 100);
            $this->app['cover_ext'] = !empty($images[0]['name']) ? '.' . end(explode('.', $images[0]['name'])) : '';
        } else {
            $this->app['curr_cover_dir'] = '';
            $this->app['cover_ext'] = '';
        }

        $attribute = $this->getVideoFilesDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        $this->prepareFormVideoCategories();
        $this->prepareOneVideo();
        $form = $this->buildForm($this->oneVideo);
        
        if ($this->saveVideoData($form, TRUE)) {
            return $this->app->redirect('video-list');
        }

        $this->app['video_id'] = $id;
        $this->app['form'] = $form->createView();
        $this->app['videoEdit'] = TRUE;
        $this->app['videoName'] = $this->oneVideo['name'];

        $quality = $this->db->getAllFromTable('quality', 'height');

        $this->app['quality'] = $this->setLocalization($quality, 'text_title');

        $allLanguages = $this->getLanguageCodesEN();
        if (is_array($allLanguages)) {
            asort($allLanguages);
        } else {
            $allLanguages = array();
        }

        $this->app['allLanguages'] = $allLanguages;

        $this->app['breadcrumbs']->addItem($this->setLocalization('Movie list'), $this->app['controller_alias'] . '/video-list');
        $this->app['breadcrumbs']->addItem($this->setLocalization('Edit movie'));

        return $this->app['twig']->render($this->getTemplateName('NewVideoClub::add_video'));
    }
    
    public function video_schedule() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $attribute = $this->getVideoScheduleDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function video_advertise() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $attribute = $this->getVideoAdvertiseDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function add_video_ads() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        $this->ads = new \VclubAdvertising();
        
        $video_category = new \VideoCategory();
        $this->video_categories = $video_category->getAll();
        $this->getVideoCatForAds();
        
        $form = $this->buildAdsForm();
        
        if ($this->saveVideoAdsData($form)) {
            return $this->app->redirect('video-advertise');
        }       
        
        $this->app['form'] = $form->createView();
        $this->app['adsEdit'] = FALSE;
        $this->app['breadcrumbs']->addItem($this->setLocalization('Advertising'), $this->app['controller_alias'] . '/video-advertise');
        $this->app['breadcrumbs']->addItem($this->setLocalization('Add commercial'));
        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    public function edit_video_ads() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        if ($this->method == 'POST' && !empty($this->postData['form']['id'])) {
            $id = $this->postData['form']['id'];
        } else if ($this->method == 'GET' && !empty($this->data['id'])) {
            $id = $this->data['id'];
        } else {
            return $this->app->redirect('add-video-ads');
        }
        
        $this->ads = new \VclubAdvertising();
        $this->ad = $this->ads->getById($id);
        $this->ad['denied_categories'] = $this->ads->getDeniedVclubCategoriesForAd($id);
        
        $video_category = new \VideoCategory();
        $this->video_categories = $video_category->getAll();
        $this->getVideoCatForAds();
        
        $form = $this->buildAdsForm($this->ad);
        
        if ($this->saveVideoAdsData($form)) {
            return $this->app->redirect('video-advertise');
        }
        
        $this->app['form'] = $form->createView();
        $this->app['adsEdit'] = TRUE;
        $this->app['adsTitle'] = $this->ad['title'];
        $this->app['breadcrumbs']->addItem($this->setLocalization('Advertising'), $this->app['controller_alias'] . '/video-advertise');
        $this->app['breadcrumbs']->addItem($this->setLocalization('Edit commercial'));
        return $this->app['twig']->render($this->getTemplateName('NewVideoClub::add_video_ads'));
    }
    
    public function video_moderators_addresses() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $attribute = $this->getVideoModeratorsAddressesDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    public function add_video_moderators(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        $form = $this->buildModForm();
        
        if ($this->saveVideoModData($form)) {
            return $this->app->redirect('video-moderators-addresses');
        }       
        
        $this->app['form'] = $form->createView();
        $this->app['modEdit'] = FALSE;
        $this->app['active_alias'] = 'video-moderators-addresses';
        $this->app['breadcrumbs']->addItem($this->setLocalization('Moderators'), $this->app['controller_alias'] . '/video-moderators-addresses');
        $this->app['breadcrumbs']->addItem($this->setLocalization('Add moderator'));
        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    public function edit_video_moderators(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        if ($this->method == 'POST' && !empty($this->postData['form']['id'])) {
            $id = $this->postData['form']['id'];
        } else if ($this->method == 'GET' && !empty($this->data['id'])) {
            $id = $this->data['id'];
        } else {
            return $this->app->redirect('add-video-moderators');
        }
        $this->mod = $this->db->getModerators(array('where' => array('id' => $id)));
        $this->mod['disable_vclub_ad'] = (bool)$this->mod['disable_vclub_ad'];
        $form = $this->buildModForm($this->mod);
        
        if ($this->saveVideoModData($form)) {
            return $this->app->redirect('video-moderators-addresses');
        }       
        
        $this->app['form'] = $form->createView();
        $this->app['modEdit'] = TRUE;
        $this->app['modName'] = $this->mod['name'];
        $this->app['breadcrumbs']->addItem($this->setLocalization('Moderators'), $this->app['controller_alias'] . '/video-moderators-addresses');
        $this->app['breadcrumbs']->addItem($this->setLocalization('Edit moderator'));
        return $this->app['twig']->render($this->getTemplateName('NewVideoClub::add_video_moderators'));
    }

    public function video_logs() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $attribute = $this->getVideoLogsDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        $param = (!empty($this->data)? $this->data: array());

        if (!empty($param['video_id'])) {
            $video = $this->db->getVideoByParam(array('id' => $param['video_id']));
            $this->app['breadcrumbs']->addItem($video['name']);
        }

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function video_categories(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $attribute = $this->getVideoCategoriesDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function video_genres(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $attribute = $this->getVideoGenresDropdownAttribute();
        $this->checkDropdownAttribute($attribute);
        $this->app['dropdownAttribute'] = $attribute;

        $allCategories = $this->db->getCategoriesGenres();

        $this->getVideoListFilters();

        if (isset($allCategories) && is_array($allCategories) && count($allCategories) > 0) {
            $this->app['allCategories'] = $this->setLocalization($allCategories, 'category_name');
        } else {
            $this->app['allCategories'] = array();
        }

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }

    public function watched_settings(){
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = $this->db->getAllFromTable('watched_settings', 'id');
        $data = !empty($data) ? $data[0] : array();
        $this->app['form'] = $this->buildWatchedSettingsForm($data)->createView();

        return $this->app['twig']->render($this->getTemplateName(__METHOD__));
    }
    
    //----------------------- ajax method --------------------------------------
    
    public function video_list_json($local_uses = FALSE) {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        $response = array(
            'data' => array(),
            'recordsTotal' => 0,
            'recordsFiltered' => 0
        );
        
        $filds_for_select = array(
            "id" => "`video`.`id` as `id`",
            "path" => "`video`.`path` as `path`",
            "name" => "`video`.`name` as `name` ",
            "o_name" => "`video`.`o_name` as `o_name`",
            "time" => "`video`.`time` as `time`",
            "cat_genre" => "'' as `cat_genre`",
            "is_series" => "`video`.`is_series` as `is_series`",
            "tasks" => "(select count(*) from moderator_tasks where media_id = video.id) as `tasks`", //moderator_tasks.ended = 0 and 
            "task_id" => "`video_on_tasks`.`id` as `task_id`",
            "count" => "`video`.`count` as `count`",
            "counter" => "(`video`.count_second_0_5 + `video`.count_first_0_5) as `counter`",
            "year" => "`video`.`year` as `year`",
            "added" => "CAST(`video`.`added` as CHAR) as `added`",
            "complaints" => "media_claims.sound_counter + media_claims.video_counter as `complaints`",
            "accessed" => "`video`.`accessed` as `accessed`"
        );
        $error = $this->setLocalization("Error");
        $param = (!empty($this->data)?$this->data: $this->postData);

        $query_param = $this->prepareDataTableParams($param, array('operations', 'RowOrder', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        }
        $filter = $this->getVideoListFilters();

        $query_param['where'] = array_merge($query_param['where'], $filter);

        if (empty($query_param['select'])) {
            $query_param['select'] = array_values($filds_for_select);
        } else {
            $query_param['select'][] = 'video.id as id';
        }

        $this->cleanQueryParams($query_param, array_keys($filds_for_select), $filds_for_select, 'order_no_replace');
        $query_param['select'][]= "media_claims.sound_counter as `sound_counter`";
        $query_param['select'][]= "media_claims.video_counter as `video_counter`";
        $query_param['select'][]= "`video_on_tasks`.`id` as `task_id`";
        $query_param['select'][]= "UNIX_TIMESTAMP(`video_on_tasks`.`date_on`) as `task_date_on`";
        $query_param['select'][]= 'UNIX_TIMESTAMP(`video_on_tasks`.`added`) as `task_added`';
        $query_param['select'][]= "cat_genre_id_1";
        $query_param['select'][]= "cat_genre_id_2";
        $query_param['select'][]= "cat_genre_id_3";
        $query_param['select'][]= "cat_genre_id_4";
        if (empty($query_param['order'])) {
            $query_param['order']['id'] = 'DESC';
        } elseif (array_key_exists('time', $query_param['order'])) {
            $query_param['order']['CAST(`time` as SIGNED)'] = $query_param['order']['time'];
            unset($query_param['order']['time']);
        }

        if (!empty($param['id']) || !empty($param['videoid'])) {
            $query_param['where']['video.id'] = !empty($param['id']) ? $param['id']:$param['videoid'];
        }

        $response['recordsTotal'] = $this->db->getTotalRowsVideoList();
        $response["recordsFiltered"] = $this->db->getTotalRowsVideoList($query_param['where'], $query_param['like']);

        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }

        $response['data'] = $this->db->getVideoList($query_param);

        $tmp_allTasks = $this->db->getAllModeratorTasks(($this->app['userlogin'] != 'admin' ? $this->app['user_id']: FALSE));
        $allTasks = array();
        if (is_array($tmp_allTasks)) {
            while (list($num, $row) = each($tmp_allTasks)) {
                $row['end_time'] = (int)$row['end_time'] * ($this->isAjax? 1000 : 1);
                $row['ended'] = (int)$row['ended'];
                $row['rejected'] = (int)$row['rejected'];
                $row['expired'] = (time() - strtotime($row['start_time'])) > 864000;
                $allTasks[$row['media_id']][] = $row;
            }
        }

        if (!empty($response['data'])) {
            $cat_genres = $this->db->getVideoCategories();
            $cat_genres = $this->setLocalization($cat_genres, 'title');
            $cat_genres = array_combine($this->getFieldFromArray($cat_genres, 'id'), $this->getFieldFromArray($cat_genres, 'title'));
            reset($response['data']);
            while (list($key, $row) = each($response['data'])){
                $response['data'][$key]['RowOrder'] = "dTRow_" . $row['id'];
                $response['data'][$key]['cat_genre'] = array();
                settype($response['data'][$key]['is_series'], 'int');
                if (!empty($row['cat_genre_id_1'])) {
                    $response['data'][$key]['cat_genre'][] = $this->mb_ucfirst($cat_genres[$row['cat_genre_id_1']]);
                }
                if (!empty($row['cat_genre_id_2'])) {
                    $response['data'][$key]['cat_genre'][] = $this->mb_ucfirst($cat_genres[$row['cat_genre_id_2']]);
                }
                if (!empty($row['cat_genre_id_3'])) {
                    $response['data'][$key]['cat_genre'][] = $this->mb_ucfirst($cat_genres[$row['cat_genre_id_3']]);
                }
                if (!empty($row['cat_genre_id_4'])) {
                    $response['data'][$key]['cat_genre'][] = $this->mb_ucfirst($cat_genres[$row['cat_genre_id_4']]);
                }
                $response['data'][$key]['cat_genre'] = implode(', ', $response['data'][$key]['cat_genre']);
                $response['data'][$key]['added'] = (int) strtotime($response['data'][$key]['added']) * ($this->isAjax? 1000 : 1);
                $response['data'][$key]['task_date_on'] = ((int)$response['data'][$key]['task_date_on']) * ($this->isAjax? 1000 : 1);
                $response['data'][$key]['accessed'] = !empty($response['data'][$key]['accessed']) ? (int)$response['data'][$key]['accessed']: 0;
                if (!array_key_exists('tasks', $response['data'][$key]) || !is_array($response['data'][$key]['tasks'])) {
                    $response['data'][$key]['tasks'] = array();
                }
                if (array_key_exists($row['id'], $allTasks)) {
                    $response['data'][$key]['tasks'] = $allTasks[$row['id']];
                }
                $response['data'][$key]['on_storages'] = (int) $this->check_video_status($row['id']);
            }
        }
        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;

        $error = "";
        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500));
        } else {
            return $response;
        }
    }
    
    public function video_info() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['videoid']) || (!is_numeric($this->postData['videoid']))) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $media_id = intval($this->postData['videoid']);
        $video = $this->db->getVideoById($media_id);
        $data = array();
        $data['action'] = 'videoinfo';
        $data['title'] = $this->setLocalization('Information about the video source');
        $data['base_info'] = $this->setLocalization('information not available');
        $data['add_info'] = array();
        $error = '';
        
        if (is_numeric($this->postData['videoid'])){
            $path = $video['path'];

            if (empty($_SERVER['TARGET'])) {
                $_SERVER['TARGET'] = 'ADM';
            }
            try{
                $master = new \VideoMaster();
                $good_storages = $master->getAllGoodStoragesForMediaFromNet($this->postData['videoid'], 0);
            } catch (\Exception $e) {
                $good_storages = array();
            }
            if (!empty($good_storages)) {
                $data['base_info'] = array();

                foreach ($good_storages as $name => $data_s){
                    $data['base_info'][] = array(
                        'storage_name' => $name,
                        'path'         => $path,
                        'series'       => (!empty($data_s['tv_series']['seasons'])? array_sum(array_map(function($season){
                            return count($season['episodes']);
                        },$data_s['tv_series']['seasons'])): (int)!empty($data_s['files'])),
                        'files'        => array_merge_recursive($data_s['files'], (!empty($data_s['tv_series']['seasons']) ? call_user_func_array('array_merge_recursive', array_map(function($season){
                            return call_user_func_array('array_merge_recursive', array_map(function($episode){
                                return array_merge_recursive($episode);
                            },$season['episodes']));
                        }, $data_s['tv_series']['seasons'])): array())),
                        'for_moderator' => $data_s['for_moderator'],
                    );
                }
            }
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function remove_video() {
        
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['videoid']) || (!is_numeric($this->postData['videoid']))) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $media_id = intval($this->postData['videoid']);
        $video = $this->db->getVideoById($media_id);
        $data = array();
        $data['action'] = 'deleteTableRow';
        $data['id'] = $media_id;
        
        $error = $this->setLocalization('Information not available');
        if ($this->db->videoLogWrite($video, 'video deleted')) {
            $result = $this->db->removeVideoById($media_id);
            if (is_numeric($result)) {
                $error = '';
                if ($result === 0) {
                    $data['nothing_to_do'] = TRUE;
                }
            }
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
    
    public function disable_video() {
        
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['videoid']) || (!is_numeric($this->postData['videoid']))) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $media_id = intval($this->postData['videoid']);
        $video = $this->db->getVideoById($media_id);
        $data = array();
        $data['action'] = 'updateTableRow';
        $data['id'] = $this->postData['videoid'];
        $data['data'] = array();
        
        $error = $this->setLocalization('Information not available');;
        if ($this->db->videoLogWrite($video, 'Unpublished')) {

            $this->db->deleteVideoTask(array("video_id" => $media_id));
            $result = $this->db->disableVideoById($media_id);
            if (is_numeric($result)) {
                $this->db->toggleDisableForHDDevices($video, 0);
                $error = '';
                if ($result === 0) {
                    $data['nothing_to_do'] = TRUE;
                }
                $data = array_merge_recursive($data, $this->video_list_json(TRUE));
            }
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
    
    public function enable_video() {
        
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['videoid']) || 
            (!is_numeric($this->postData['videoid'])) || empty($this->postData['video_on_date'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'updateTableRow';
        $data['id'] = $this->postData['videoid'];
        $data['data'] = array();
        $error = $this->setLocalization('Information not available');
        
        $media_id = intval($this->postData['videoid']);
        $date_on = date("Y-m-d", strtotime($this->postData['video_on_date']));
        $files = $this->checkPublishedVideoFiles($media_id);

        if ($date_on == date("Y-m-d")) {
            if (!empty($files)) {
                $error = !((bool) $this->db->deleteVideoTask(array("video_id" => $media_id)));
                $video = $this->db->getVideoById($media_id);

                if ($this->db->videoLogWrite($video, 'Published')) {
                    $result = $this->db->enableVideoById($media_id);
                    if (is_numeric($result)) {
                        $this->db->toggleDisableForHDDevices($video, 1);
                        $error = '';
                        if ($result === 0) {
                            $data['nothing_to_do'] = TRUE;
                        }
                    }
                }
                $data = array_merge_recursive($data, $this->video_list_json(TRUE));
            } else {
                $data['msg'] = $this->setLocalization('You can not publish this entry. There are no available video file for this entry.');
            }
        } else {
            if (!empty($files)) { // || !empty($this->postData['empty_confirm'])
                $data_in = array(
                    'video_id' => $media_id,
                    'date_on' => $date_on
                );

                $video_id = $this->db->getVideoTaskByVideoId($media_id);

                if (empty($video_id)) {
                    $error = !((bool) $this->db->addVideoTask($data_in));
                } else {
                    $this->db->updateVideoTask($data_in, array("video_id"=>$media_id));
                    $error = '';
                }
                $data_in['date_on'] = strftime("%e-%m-%Y", strtotime($data_in['date_on']));
                $data = array_merge($data_in, $data);
            } else {
                $data['msg'] = $this->setLocalization('You can not publish this entry. There are no available video file for this entry.');
                /*$data['action'] = 'publishWarning';
                $data['nothing_to_do'] = 1;
                $error = '';
                $data['msg'] = $this->setLocalization('There are no available video file for this entry. Do you really wont schedule publishing this record on {date}?', '', $date_on, array('{date}' => $date_on));*/
            }
            $data = array_merge_recursive($data, $this->video_list_json(TRUE));
        }

        if (!empty($this->postData['rowid'])) {
            $data['RowOrder'] = $this->postData['rowid'];
            if (!empty($this->postData['waiting']) && is_numeric($this->postData['waiting'])) {
                $data['action'] = 'deleteTableRow';
            }
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
    
    public function get_md5() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['storage_name']) || empty($this->postData['media_name'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'setMD5';
        $error = $this->setLocalization('Information not available');

        if (empty($_SERVER['TARGET'])) {
            $_SERVER['TARGET'] = 'ADM';
        }
        $master = new \VideoMaster();
        ob_start();
        try {
            $data['data'] = $master->startMD5Sum($this->postData['storage_name'], $this->postData['media_name']);
            $error = '';
        }catch (\Exception $exception){
            $error = $exception->getMessage();
        }
        $data['md5_data'] = ob_get_contents();
        ob_end_clean();
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
    
    public function create_tasks(){
        if (!$this->isAjax || $this->method != 'POST') {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data['action'] = 'updateTableRow';
        $data['id'] = $this->postData['videoid'];
        $data['data'] = array();
        $error = $this->setLocalization('Information not available');

        if (empty($this->postData['sendData']['id']) || empty($this->postData['sendData']['to_usr']) || empty($this->postData['sendData']['comment'])) {
            $error = $this->setLocalization('Not all fields are filled');
        } else {
            $data_in = $this->postData['sendData'];
            $data_in['task_id'] = $this->db->setModeratorTask($data_in);
            $data_in['uid'] = $_SESSION['uid'];
            $video = $this->db->getVideoById($data_in['id']);
            if ($data_in['task_id'] && $this->db->setModeratorHistory($data_in)){
                $this->db->videoLogWrite($video, serialize(array('task'=>$data_in['task_id'], 'event'=>'task open')), $data_in['to_usr']);
                $error = '';
                $data = array_merge_recursive($data, $this->video_list_json(TRUE));
            }
        }
        
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
    
    public function check_name() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['name'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'checkData';
        $data['input_id'] = 'form_name';
        $error = $this->setLocalization('Name is busy');
        if ($this->db->checkName($this->postData)) {
            $data['chk_rezult'] = $this->setLocalization('Name is busy');
        } else {
            $data['chk_rezult'] = $this->setLocalization('Name is available');
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function check_moderator_mac() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['mac'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'checkData';
        $data['input_id'] = 'form_mac';
        $error = $this->setLocalization("Address is busy");
        if (preg_match('/([0-9a-fA-F]{2}([:]|$)){6}$/', trim($this->postData['mac']))) {
            $params = array('mac' => trim($this->postData['mac']));
            if (!empty($this->postData['id'])) {
                $params['id<>'] = $this->postData['id'];
            }
            if ($this->db->checkModMac($params)) {
                $data['chk_rezult'] = $this->setLocalization("Address is busy");
            } else {
                $data['chk_rezult'] = $this->setLocalization("Address is available");
                $error = '';
            }
        } else {
            $data['chk_rezult'] = $this->setLocalization("Error: Not valid mac address");
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
    
    public function edit_cover() {
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        if (empty($this->postData['id']) || (!is_numeric($this->postData['id']) && strpos($this->postData['id'], 'new') === FALSE)) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        $data = array();
        /*$data['action'] = 'editCover';*/
        $error = $this->setLocalization("Information not available");

        if (!empty($_FILES)){
            list($f_key, $tmp) = each($_FILES);
            if (is_uploaded_file($tmp['tmp_name']) && preg_match("/jpeg|jpg|png/",$tmp['type'])){

                if ($this->postData['id'] != 'new' && is_numeric($this->postData['id'])) {
                    $screenshot = $this->db->getScreenshotData(array('id' => $this->postData['id']));
                    if (!empty($screenshot)) {
                        $file_info = pathinfo($screenshot[0]['name']);
                        $this->db->removeScreenshotData($this->postData['id']);
                        $img_path = $this->getCoverFolder($this->postData['id']);
                        $img_path = trim(str_replace(str_replace('/admin', '', $this->baseDir), "", $img_path), '/');
                        @unlink($this->baseDir . rtrim(Config::getSafe('portal_url', '/stalker_portal/'), "/") . $img_path . '/' . $this->postData['id'] . (!empty($this->postData['file_num']) ? '_' . $this->postData['file_num'] : '') . '.'.$file_info['extension']);
                    }
                }

                $s_data = array(
                    'name' => $tmp['name'],
                    'size' => $tmp['size'],
                    'type' => $tmp['type'],
                    'media_id' => $this->postData['video_id'],
                    'video_episodes' => $this->postData['file_num']
                );

                $upload_id = $this->db->saveScreenshotData($s_data);
                $img_path = $this->getCoverFolder($upload_id);
                umask(0);

                try{
                    $uploaded = $this->request->files->get($f_key)->getPathname();
                    if (!empty($this->postData['file_num'])) {
                        $w = 320;
                        $h = 240;
                    } else {
                        $w = 240;
                        $h = 320;
                    }
                    $ext = end(explode('.', $s_data['name']));
                    $this->app['imagine']->open($uploaded)->resize(new Box($w, $h))->save($img_path.'/'.$upload_id . (!empty($this->postData['file_num']) ? '_' . $this->postData['file_num'] : '') .".$ext");
                    chmod($img_path.'/'.$upload_id . (!empty($this->postData['file_num']) ? '_' . $this->postData['file_num'] : '') . ".$ext", 0644);
                    $error = '';
                } catch (\ImagickException $e) {
                    $error = sprintf(_('Error during file moving from %s to %s'), $tmp['tmp_name'], $img_path.'/'.$upload_id . (!empty($this->postData['file_num']) ? '_' . $this->postData['file_num'] : ''));
                }
            }
        }
        $img_path = trim(str_replace(str_replace('/admin', '', $this->baseDir), "", $img_path), '/');
        $response = $this->generateAjaxResponse(array(
            'pic' => $this->baseHost . rtrim(Config::getSafe('portal_url', '/stalker_portal/')) . $img_path.'/'.$upload_id . (!empty($this->postData['file_num']) ? '_' . $this->postData['file_num'] : '').".$ext",
            'upload_id' => !empty($upload_id) ? $upload_id : 0,
            'file_num' => !empty($this->postData['file_num']) ? $this->postData['file_num']: 0
        ), $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function delete_cover($local_id = FALSE) {
        if ((!$this->isAjax || $this->method != 'POST' || empty($this->postData['id'])) && $local_id === FALSE) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $cover_id = ($local_id !== FALSE) ? $local_id : $this->postData['id'];

        $data = array();
        $data['action'] = 'deleteCover';
        $error = $this->setLocalization('Failed');
        if (($screenshot = $this->db->getScreenshotData(array('id' => $cover_id), 'ALL')) && !empty($screenshot)) {
            $screenshot = $screenshot[0];
            $img_path = $this->getCoverFolder($screenshot['id']);
            $ext = !empty($screenshot['name']) ? end(explode('.', $screenshot['name'])): '';
            $filename = $img_path . '/' . $screenshot['id'] . (!empty($screenshot['video_episodes']) ? '_' . $screenshot['video_episodes'] : '') . ".$ext";

            if ($this->db->removeScreenshotData($screenshot['id']) && is_file($filename)) {
                try{
                    unlink($filename);
                    $error = '';
                    $data['msg'] = $this->setLocalization('Deleted');
                    $data['id'] = (!empty($this->postData['container']) ? $this->postData['container']: '');
                } catch (\Exception $e){
                    $error = $this->setLocalization('image file has not been deleted') . ', ';
                    $error .= $this->setLocalization('image name') . ' - "' . $screenshot['id'] . (!empty($screenshot['video_episodes']) ? '_' . $screenshot['video_episodes'] : '') . ".$ext" . '", ';
                    $error .= $this->setLocalization('file can be deleted manually from screenshot directory');
                    $data['msg'] = $error;
                }
            } else {
                $data['msg'] = $error = $this->setLocalization("No information about") . ' - "' . $cover_id . (!empty($screenshot['video_episodes']) ? '_' . $screenshot['video_episodes'] : '') . ".$ext \"" . $this->setLocalization('or file is not exists');
            }
        }

        if ($local_id === FALSE) {
            $response = $this->generateAjaxResponse($data, $error);

            return new Response(json_encode($response), (empty($error) ? 200 : 500));
        } else {
            return $error;
        }
    }
    
    public function update_rating_kinopoisk() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['data'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'updateRatingKinopoisk';
        $error = $this->setLocalization('No data');
        
        try {
            $data['result'] = \Vclubinfo::getRatingByName($this->postData['data']);
            $error = '';
        } catch (\KinopoiskException $e) {
            $error = $e->getMessage();

            $logger = new \Logger();
            $logger->setPrefix("kinopoisk_");

            // format: [date] - error_message - [base64 encoded response];
            $logger->error(sprintf("[%s] - %s - \"%s\"\n", date("r"), $e->getMessage(), base64_encode($e->getResponse())));
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
    
    public function get_kinopoisk_info_by_name() {
       
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'fillFormData';
        $error = $this->setLocalization('No data');
        
        try {
            $data['result'] = \Vclubinfo::getInfoByName($this->postData['data'], (!empty($this->postData['provider']) ? $this->postData['provider']: FALSE));
            $error = '';
        } catch (\Exception $e) {
            $error = $e->getMessage();

            $logger = new \Logger();
            $logger->setPrefix((!empty($this->postData['provider']) ? $this->postData['provider']: 'kinopoisk') . "_");

            // format: [date] - error_message - [base64 encoded response];
            $logger->error(sprintf("[%s] - %s - \"%s\"\n", date("r"), $e->getMessage(), base64_encode($e->getResponse())));
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
    
    public function get_kinopoisk_info_by_id() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['data'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'fillFormData';
        $error = $this->setLocalization('No data');
        
        try {
            $data['result'] = \Vclubinfo::getInfoById($this->postData['data'], (!empty($this->postData['provider']) ? $this->postData['provider']: FALSE));
            $error = '';
        } catch (\Exception $e) {
            $error = $e->getMessage();

            $logger = new \Logger();
            $logger->setPrefix((!empty($this->postData['provider']) ? $this->postData['provider']: 'kinopoisk') . "_");

            // format: [date] - error_message - [base64 encoded response];
            $logger->error(sprintf("[%s] - %s - \"%s\"\n", date("r"), $e->getMessage(), base64_encode($e->getResponse())));
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
    
    public function get_image() {
        if ($this->method != 'GET' || empty($this->data['url'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'getImage';
        $error = $this->setLocalization('No data');

        if ((strpos($this->data['url'], 'http://') === 0 || strpos($this->data['url'], 'https://') === 0) && (strpos($this->data['url'], 'kinopoisk.ru/') || strpos($this->data['url'], 'image.tmdb.org/'))){
            $img = file_get_contents($this->data['url']);
            if (!empty($img)) {
                echo $img;
                exit;
            }
        }
        
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function video_schedule_list_json($local_uses = FALSE) {
        if ($this->isAjax) {
            if ($no_auth = $this->checkAuth()) {
                return $no_auth;
            }
        }

        $response = array(
            'data' => array(),
            'recordsTotal' => 0,
            'recordsFiltered' => 0
        );

        $error = $this->setLocalization("Error");
        $param = (!empty($this->data)?$this->data: $this->postData);

        $query_param = $this->prepareDataTableParams($param, array('operations', 'RowOrder', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        }

        $filds_for_select = $this->getVideoScheduleFields();

        $query_param['select'] = array_values($filds_for_select);

        $this->cleanQueryParams($query_param, array_keys($filds_for_select), $filds_for_select);

        foreach($query_param['order'] as $key => $val){
            if ($search = array_search($key, $filds_for_select )){
                $new_key = str_replace(" as $search", '', $key);
                unset($query_param['order'][$key]);
                $query_param['order'][$new_key] = $val;
            }
        }

        if (!isset($query_param['like'])) {
            $query_param['like'] = array();
        } elseif (array_key_exists('video_on_tasks.added', $query_param['like'])) {
            $query_param['like']['CAST(`video_on_tasks`.`added` as CHAR)'] = $query_param['like']['video_on_tasks.added'];
            unset($query_param['like']['video_on_tasks.added']);
        }

        $response['recordsTotal'] = $this->db->getTotalRowsAllVideoTasks();
        $response["recordsFiltered"] = $this->db->getTotalRowsAllVideoTasks($query_param['where'], $query_param['like']);
        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }

        $allTasks = $this->db->getAllVideoTasks($query_param);

        if (is_array($allTasks)) {
            reset($allTasks);
            while (list($num, $row) = each($allTasks)) {
                $allTasks[$num]['task_date_on'] = ((int)$allTasks[$num]['task_date_on']) * ($this->isAjax? 1000 : 1);
                $allTasks[$num]['task_added'] = strtotime($row['task_added']);
                if ($allTasks[$num]['task_added'] < 0) {
                    $allTasks[$num]['task_added'] = 0;
                }
                $allTasks[$num]['RowOrder'] = "dTRow_" . $row['id'];
            }
            $response["data"] = $allTasks;
        }
        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;
        $error = "";

        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500));
        } else {
            return $response;
        }
    }

    public function video_advertise_list_json($local_uses = FALSE) {

        if ($this->isAjax) {
            if ($no_auth = $this->checkAuth()) {
                return $no_auth;
            }
        }

        $error = $this->setLocalization("Error");
        $param = (!empty($this->data)?$this->data: $this->postData);

        $query_param = $this->prepareDataTableParams($param, array('operations', 'RowOrder', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        }

        if (!empty($param['id'])) {
            $query_param['where']['id'] = $param['id'];
        }

        $filds_for_select = $this->getVideoAdvertiseFields();

        $query_param['select'] = array_values($filds_for_select);

        if (!empty($query_param['like'])) {
            if (array_key_exists('started', $query_param['like'])) {
                unset($query_param['like']['started']);
            }
            if (array_key_exists('ended', $query_param['like'])) {
                unset($query_param['like']['ended']);
            }
        }
        $this->cleanQueryParams($query_param, array_keys($filds_for_select), $filds_for_select);

        foreach($query_param['order'] as $key => $val){
            if (array_key_exists($key, $filds_for_select )){
                $new_key = preg_replace('/\s+as\s+`?' . $key . '`?', '', $filds_for_select[$key]);
                unset($query_param['order'][$key]);
                $query_param['order'][$new_key] = $val;
            }
        }

        $response['recordsTotal'] = $this->db->getAdsTotalRows();
        $response["recordsFiltered"] = $this->db->getAdsTotalRows($query_param['where'], $query_param['like']);

        $must_watch = $this->setLocalization('all');
        $response["data"] = array_map(function($row) use ($must_watch){
            if (!is_numeric($row['must_watch'])) {
                $row['must_watch'] = $must_watch;
            }
            settype($row['status'], 'int');
            $row['RowOrder'] = "dTRow_" . $row['id'];
            return $row;
        }, $this->db->getAdsList($query_param));

        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;
        $error = "";

        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500));
        } else {
            return $response;
        }
    }

    public function remove_tasks() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['taskid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'deleteTableRow';
        $data['id'] = $this->postData['taskid'];

        $error = $this->setLocalization('Failed');

        $result = $this->db->deleteVideoTask(array('id'=>$this->postData['taskid']));
        if (is_numeric($result)) {
            $error = '';
            if ($result === 0) {
                $data['nothing_to_do'] = TRUE;
            }
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function remove_video_ads() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['adsid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'deleteTableRow';
        $error = $this->setLocalization('Failed');
        $ad = new \VclubAdvertising();

        $result = $ad->delById($this->postData['adsid'])->total_rows();
        if (is_numeric($result)) {
            $data['id'] = $this->postData['adsid'];
            $error = '';
            if ($result === 0) {
                $data['nothing_to_do'] = TRUE;
            }
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
    
    public function toggle_video_ads_status() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['adsid']) || !isset($this->postData['adsstatus'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        $data = array();
        $data['action'] = 'updateTableRow';
        $data['id'] =$this->postData['adsid'];
        $error = $this->setLocalization('Failed');
        $ad = new \VclubAdvertising();
        
        if ($ad->updateById((int) $this->postData['adsid'], array('status' => (int) $this->postData['adsstatus'], 'denied_categories' => $ad->getDeniedVclubCategoriesForAd((int) $this->postData['adsid'])))) {
            $this->postData['id'] = $this->postData['adsid'];
            $data = array_merge_recursive($data, $this->video_advertise_list_json(TRUE));
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function video_moderators_addresses_list_json($local_uses = FALSE) {
        if ($this->isAjax) {
            if ($no_auth = $this->checkAuth()) {
                return $no_auth;
            }
        }

        $response = array(
            'data' => array(),
            'recordsTotal' => 0,
            'recordsFiltered' => 0
        );

        $error = $this->setLocalization("Error");
        $param = (!empty($this->data)?$this->data: $this->postData);;

        $query_param = $this->prepareDataTableParams($param, array('operations', 'RowOrder', '_'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        }
        if (!empty($param['id'])) {
            $query_param['where']['id'] = $param['id'];
        }

        $filds_for_select = $this->getVideoModeratorsAddressesFields();

        $query_param['select'] = array_values($filds_for_select);

        $this->cleanQueryParams($query_param, array_keys($filds_for_select), $filds_for_select);

        foreach($query_param['order'] as $key => $val){
            if ($search = array_search($key, $filds_for_select )){
                $new_key = str_replace(" as $search", '', $key);
                unset($query_param['order'][$key]);
                $query_param['order'][$new_key] = $val;
            }
        }

        if (!isset($query_param['like'])) {
            $query_param['like'] = array();
        }

        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }

        $response['recordsTotal'] = $this->db->getTotalRowsModerators();
        $response['recordsFiltered'] = $this->db->getTotalRowsModerators($query_param['where'], $query_param['like']);

        $allModerators = $this->db->getModerators($query_param);
        if (is_array($allModerators)) {
            $response["data"] = array_map(function ($row) {
                settype($row['status'], 'int');
                settype($row['disable_vclub_ad'], 'int');
                $row['RowOrder'] = "dTRow_" . $row['id'];
                return $row;
            }, $allModerators);
        }

        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;

        $error = "";

        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500));
        } else {
            return $response;
        }
    }

    public function remove_video_moderators() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['modid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        $data = array();
        $data['action'] = 'deleteTableRow';
        $data['id'] = $this->postData['modid'];
        $error = $this->setLocalization('Failed');
        if ($this->db->deleteModeratorsById($this->postData['modid'])){
            $error = '';
        }
        
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
    
    public function toggle_video_moderators_status() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['modid']) || !isset($this->postData['modstatus'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }
        
        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        
        $data = array();
        $data['action'] = 'updateTableRow';
        $data['id'] = $this->postData['modid'];
        $this->postData['id'] = $this->postData['modid'];
        $error = $this->setLocalization('Failed');
        
        if ($this->db->updateModeratorsById((int) $this->postData['modid'], array('status' => (int) $this->postData['modstatus']))) {
            $data = array_merge_recursive($data, $this->video_moderators_addresses_list_json(TRUE));
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
        
    public function video_logs_json($param = array()) {
        $response = array();
        $fields = array(
            'id'=>'`video_log`.`id` as `id`', 
            'video_id'=>'`video_log`.`video_id` as `video_id`',
            'login'=>'`administrators`.`login` as `login`', 
            'actiontime'=>'`actiontime`', 
            '`video`.`name`'=>'IF(ISNULL(`video`.`name`), `video_log`.`video_name`, `video`.`name`) as `video_name`',
            'action'=>'`action`'
        );
        
        if ($this->isAjax) {
            if ($no_auth = $this->checkAuth()) {
                return $no_auth;
            }
        }
        
        $param = (!empty($this->data)? $this->data: array());
        
        $query_param = $this->prepareDataTableParams($param);

        if (!array_key_exists('where', $query_param)) {
            $query_param['where'] = array();
        }

        if($this->app['userlogin'] == 'admin') {
            $query_param['where']["moderator_id"] = $this->app['user_id'];
        }

        if (!empty($this->data['video_id'])) {
            $query_param['where']['video_id'] = $this->data['video_id'];
        }
        
        $query_param['select'] = array_merge($query_param['select'], array_diff($fields, $query_param['select']));
        
        if (empty($query_param['order'])) {
            $query_param['order']['actiontime'] = 'desc';
        }
        
        $this->cleanQueryParams($query_param, array_keys($fields), $fields);
        
        if (!empty($query_param['like']) && array_key_exists('`actiontime`', $query_param['like'])) {
            $query_param['like']['CAST(`actiontime` as CHAR)'] = $query_param['like']['`actiontime`'];
            unset($query_param['like']['`actiontime`']);
        }

        $response['recordsTotal'] = $this->db->getTotalRowsVideoLog($query_param['where']);
        $response["recordsFiltered"] = $this->db->getTotalRowsVideoLog($query_param['where'], $query_param['like']);
        
        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        }
        $response['data'] = $this->db->getVideoLog($query_param);
        $response['data'] = array_map(function($row){
            $row['actiontime'] = (int)  strtotime($row['actiontime']);
            return $row;
        }, $response['data']);
        $response['data'] = $this->setLocalization($response['data'], 'action');

        $this->setLinksForVideoLog($response['data']);
        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw']: 1;
        
        
        if ($this->isAjax) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500));
        } else {
            return $response;
        }
    }

    public function video_categories_list_json($internal_use = FALSE){

        if ($this->isAjax) {
            if ($no_auth = $this->checkAuth()) {
                return $no_auth;
            }
        }
        $response = array(
            'data' => array(),
            'recordsTotal' => 0,
            'recordsFiltered' => 0
        );

        $error = $this->setLocalization('Error');
        $param = (!empty($this->data)?$this->data: $this->postData);

        $query_param = $this->prepareDataTableParams($param, array('operations', '_', 'localized_title', 'RowOrder'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        }

        $filds_for_select = $this->getVideoCategoryFields();

        $query_param['select'] = array_values($filds_for_select);

        $this->cleanQueryParams($query_param, array_keys($filds_for_select), $filds_for_select);

        if (!(empty($param['id']))) {
            $query_param['where']['id'] = $param['id'];
        }

        $response['recordsTotal'] = $this->db->getTotalRowsCategoriesGenresList();
        $response["recordsFiltered"] = $this->db->getTotalRowsCategoriesGenresList($query_param['where'], $query_param['like']);

        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }

        if (!empty($query_param['select']) && !in_array('id', $query_param['select'])) {
            $query_param['select'][] = 'id';
        }

        $query_param['order']['num'] = 'ASC';

        $self = $this;
        $response['data'] = array_map(function($row) use ($self){
            $row['censored'] = (int)$row['censored'];
            $row['localized_title'] = $self->setLocalization($row['category_name']);
            $row['RowOrder'] = "dTRow_" . $row['id'];
            return $row;
        }, $this->db->getCategoriesGenres($query_param));

        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;

        $error = "";
        if ($this->isAjax && !$internal_use) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500));
        } else {
            return $response;
        }
    }

    public function video_categories_reorder() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $matches = array();
        $data = array();
        $data['action'] = 'updateTableData';
        $data['id'] = $this->postData['id'];
        $error = $this->setLocalization('error');
        if (preg_match("/(\d+)/i", $this->postData['id'], $matches) && preg_match("/(\d+)/i", $this->postData['target_id'], $matches_1)){
            if ($this->db->mowingCategoriesRows($matches[1], $this->postData['fromPosition'], $this->postData['toPosition'], $this->postData['direction'])){
                $error = '';
            }
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function add_video_categories(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['category_name']) || empty($this->postData['num'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'addVideoCategory';
        $error = $this->setLocalization('Failed');

        $category_alias  = $this->transliterate($this->postData['category_name']);

        $check = $this->db->getCategoriesGenres(array(
            'where' => array(
                'category_name' => $this->postData['category_name'],
                'category_alias' => $category_alias,
                'num' => $this->postData['num']
            )));

        if (empty($check)) {
            $data['id']  = $this->db->insertCategoriesGenres(array(
                'category_name' => $this->postData['category_name'],
                'num' => $this->postData['num'],
                'category_alias' => $category_alias,
                'censored' => !empty($this->postData['censored'])
            ));
            $data['category_name'] = $this->postData['category_name'];
            $error = '';
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function edit_video_categories(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['category_name']) || empty($this->postData['id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'updateTableRow';
        $data['id'] = $this->postData['id'];
        $data['data'] = array();
        $error = $this->setLocalization('Failed');

        $check = $this->db->getCategoriesGenres(array(
            'select'=>array('*'),
            'where' => array(
                'category_name' => $this->postData['category_name'],
                'num' => $this->postData['num'],
                'id<>' => $this->postData['id']
            ),
            'order' => array('category_name' => 'ASC'),
            'like' => array()
        ));
        if (empty($check)) {
            $this->db->updateCategoriesGenres(array(
                'category_name' => $this->postData['category_name'],
                'num' => $this->postData['num'],
                'censored' => !empty($this->postData['censored'])
            ), array('id' => $this->postData['id']));
            $error = '';
            $data = array_merge_recursive($data, $this->video_categories_list_json(TRUE));
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function remove_video_categories(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['categoriesid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'deleteTableRow';
        $data['id'] = $this->postData['categoriesid'];
        $this->db->mowingCategoriesRows($this->postData['categoriesid'], $this->postData['curr_pos'], 1000000, 'forward');
        $this->db->deleteCategoriesGenres(array('id' => $this->postData['categoriesid']));
        $response = $this->generateAjaxResponse($data, '');

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function check_video_categories_name(){

        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['category_name'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'checkData';
        $data['input_id'] = 'video_category_name';
        $error = $this->setLocalization('Name already used');

        $id = array_key_exists('id', $this->postData) ? $this->postData['id']: '';

        if ($this->db->getCategoriesGenres(array('where' => array(' BINARY category_name' => $this->postData['category_name'], 'id<>' => $id))) ||
            (empty($id) ? $this->db->getCategoriesGenres(array('where' => array(' BINARY category_alias' => $this->transliterate($this->postData['category_name'])))): 0)) {
            $data['chk_rezult'] = $this->setLocalization('Name already used');
        } else {
            $data['chk_rezult'] = $this->setLocalization('Name is available');
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));

    }

    public function check_video_categories_number(){

        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['category_num'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'checkData';
        $data['input_id'] = 'video_num';
        $error = $this->setLocalization('Number already used');

        $id = array_key_exists('id', $this->postData) ? $this->postData['id']: '';

        if ($this->db->getCategoriesGenres(array('where' => array(' num ' => $this->postData['category_num'], 'id<>' => $id)))) {
            $data['chk_rezult'] = $this->setLocalization('Number already used');
        } else {
            $data['chk_rezult'] = $this->setLocalization('Number is available');
            $error = '';
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));

    }

    public function video_genres_list_json($local_uses = FALSE){

        if ($this->isAjax) {
            if ($no_auth = $this->checkAuth()) {
                return $no_auth;
            }
        }
        $response = array(
            'data' => array(),
            'recordsTotal' => 0,
            'recordsFiltered' => 0
        );

        if (!$local_uses) {
            $response['action'] = 'openModalBox';
        }

        $error = $this->setLocalization('Error');
        $param = (!empty($this->data)?$this->data: $this->postData);

        $query_param = $this->prepareDataTableParams($param, array('operations', '_', 'localized_title', 'category_name', 'RowOrder'));

        if (!isset($query_param['where'])) {
            $query_param['where'] = array();
        }

        if (!empty($this->postData['id'])) {
            $query_param['where']['cat_genre.id'] = $this->postData['id'];
        }

        $filter = $this->getVideoListFilters();

        $query_param['where'] = array_merge($query_param['where'], $filter);

        $filds_for_select = $this->getVideoCategoryGenresFields();

        $query_param['select'] = array_values($filds_for_select);

        $order = array();
        if (!empty($query_param['order']['movie_in_genre'])) {
            $order = $query_param['order'];
        }

        if (!empty($query_param['like']['movie_in_genre'])) {
            unset($query_param['like']['movie_in_genre']);
        }

        $this->cleanQueryParams($query_param, array_keys($filds_for_select), $filds_for_select);

        $response['recordsTotal'] = $this->db->getTotalRowsVideoCatGenresList();
        $response["recordsFiltered"] = $this->db->getTotalRowsVideoCatGenresList($query_param['where'], $query_param['like']);

        if (empty($query_param['limit']['limit'])) {
            $query_param['limit']['limit'] = 50;
        } elseif ($query_param['limit']['limit'] == -1) {
            $query_param['limit']['limit'] = FALSE;
        }

        if (empty($query_param['select'])) {
            $query_param['select'][] = '*';
        }

        if (!empty($order)) {
            $query_param['order'] = $order;
        }

        $cat_genre = $this->setLocalization($this->db->getAllFromTable('cat_genre', 'id'), 'title');
        $cat_genre_localised = array_combine($this->getFieldFromArray($cat_genre, 'id'), $this->getFieldFromArray($cat_genre, 'title'));

        $media_category = $this->setLocalization($this->db->getAllFromTable('media_category', 'id'), 'category_name');
        $media_category_localised = array_combine($this->getFieldFromArray($media_category, 'id'), $this->getFieldFromArray($media_category, 'category_name'));
        $media_category_aliases = array_combine($this->getFieldFromArray($media_category, 'id'), $this->getFieldFromArray($media_category, 'category_alias'));

        $response['data'] = array_map(function($row) use ($cat_genre_localised, $media_category_localised, $media_category_aliases){
            $row['localized_title'] = $cat_genre_localised[$row['id']];
            $row['category'] = $media_category_localised[$row['category_id']];
            $row['category_alias'] = $media_category_aliases[$row['category_id']];
            $row['RowOrder'] = "dTRow_" . $row['id'];
            return $row;
        }, $this->db->getVideoCatGenres($query_param));

        $response["draw"] = !empty($this->data['draw']) ? $this->data['draw'] : 1;

        $error = "";
        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500));
        } else {
            return $response;
        }
    }

    public function save_video_genres(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['title']) || empty($this->postData['category_alias'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'updateTableData';
        $data['data'] = array();
        $error = $this->setLocalization('Failed');

        $where = array(
            'cat_genre.title' => $this->postData['title'],
            'cat_genre.category_alias' => $this->postData['category_alias']
        );
        $operation_params = array(
            'data' => array_filter($this->postData)
        );

        if (!empty($this->postData['id'])) {
            $operation = 'update';
            $where['cat_genre.id<>'] = $this->postData['id'];
            $operation_params['where'] = array('cat_genre.id' => $operation_params['data']['id']);
            unset($operation_params['data']['id']);
            $data['action'] = 'updateTableRow';
            $data['id'] = $this->postData['id'];
        } else {
            $operation = 'insert';
        }

        $check = $this->db->getVideoCatGenres(array('where' => $where));

        if (empty($check)) {
            $data['msg'] = ($operation == 'insert' ? $this->setLocalization( 'inserted') : $this->setLocalization('updated'));
            $result = call_user_func(array($this->db, $operation."VideoCatGenres"), $operation_params);
            if (is_numeric($result)) {
                $error = '';
                if ($result === 0 || $operation !== 'insert') {
                    $data['nothing_to_do'] = TRUE;
                }
            }
            if (!empty($this->postData['id'])) {
                $data = array_merge_recursive($data, $this->video_genres_list_json(TRUE));
            }
        } else {
            $error = $this->setLocalization('In this category already exists such a genre');
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function remove_video_genres(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['genresid'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'deleteTableRow';
        $data['id'] = $this->postData['genresid'];
        $error = $this->setLocalization('Failed');
        $result = $this->db->deleteVideoCatGenres(array('id' => $this->postData['genresid']));
        if (is_numeric($result)) {
            $error = '';
            if ($result === 0) {
                $data['nothing_to_do'] = TRUE;
            }
            $data['msg'] = $this->setLocalization('Deleted');
        }

        $response = $this->generateAjaxResponse($data, '');

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function get_video_season_list_json(){
        if (!$this->isAjax || $this->method != 'POST') {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        $video_id = !empty($this->data['video_id']) ? $this->data['video_id'] : (!empty($this->postData['video_id']) ? $this->postData['video_id'] : FALSE);

        if ($video_id === FALSE) {
            return new Response(json_encode($this->generateAjaxResponse(array('action'=>'emptyFileContainer', 'success' => TRUE), '')), 200);
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'action' => 'setSerial'
        );

        if (empty($_SERVER['TARGET'])) {
            $_SERVER['TARGET'] = 'ADM';
        }
        try{
            $master = new \VideoMaster();
            $storages = $master->getAllGoodStoragesForMediaFromNet($video_id, 0);
        } catch (\Exception $e) {
            $storages = array();
        }
        if (!empty($storages)) {
            $storages = call_user_func_array('array_replace_recursive', $storages);
        } else {
            $storages['tv_series'] = array();
            $storages['files'] = array();
        }

        if (array_key_exists('tv_series', $storages) && is_array($storages['tv_series']) && array_key_exists('seasons', $storages['tv_series']) && is_array($storages['tv_series']['seasons'])) {
            $tv_series = $storages['tv_series']['seasons'];
            ksort($tv_series);
        } else {
            $tv_series = array();
        }
        $db_season_data = $this->db->getSeasonData(array('V_S.video_id' => $video_id));

        $data['data'] = array();

        /*print_r($db_season_data); exit;*/

        while(list($num, $row) = each($db_season_data)){

            if (!array_key_exists($row['season_number'], $data['data'])) {
                $data['data'][$row['season_number']] = array(
                    'season_id' => $row['season_id'],
                    'season_number' => $row['season_number'],
                    'season_name' => $row['season_name'],
                    'season_original_name' => $row['season_original_name'],
                    'series_count' => $row['series_count'],
                    'season_series' => array()
                );
            }

            if (!empty($row['series_number']) && !array_key_exists($row['series_number'], $data['data'][$row['season_number']]['season_series'])) {
                $data['data'][$row['season_number']]['season_series'][$row['series_number']] = array(
                    'series_id' => $row['series_id'],
                    'series_number' => $row['series_number'],
                    'series_name' => $row['series_name'],
                    'series_original_name' => $row['series_original_name'],
                    'series_files' => $row['series_files'],
                    'files_names' => array()
                );
            }
            $data['data'][$row['season_number']]['season_series'][$row['series_number']]['files_names'][] = $row['file_name'];
        }
        if (!empty($tv_series)) {
            while(list($num, $season) = each($tv_series)) {
                if (!array_key_exists($num, $data['data'])) {
                    $data['data'][$num] = array(
                        'season_id' => $this->db->insertSeason(array('video_id'=> $video_id, 'season_number' => $num,'season_series' => count($season['episodes']), 'date_add' => 'NOW()', 'date_modify' => 'NOW()')),
                        'season_number' => $num,
                        'season_name' => '',
                        'season_original_name' => '',
                        'series_count' => count($season['episodes']),
                        'season_series' => array()
                    );
                }

                while(list($num_e, $episodes) = each($season['episodes'])){
                    if (!array_key_exists($num_e, $data['data'][$num]['season_series'])) {
                        $data['data'][$num]['season_series'][$num_e] = array(
                            'series_id' => $this->db->insertSeries(array('season_id' => $data['data'][$num]['season_id'], 'series_number' => $num_e,'series_files' => count($episodes), 'date_add' => 'NOW()', 'date_modify' => 'NOW()')),
                            'series_number' => $num_e,
                            'series_name' => '',
                            'series_original_name' => '',
                            'series_files' => count($episodes)
                        );
                    } else {
                        $files_names = $data['data'][$num]['season_series'][$num_e]['files_names'];
                        unset($data['data'][$num]['season_series'][$num_e]['files_names']);
                        $files_names = array_merge(array_map(function($row) use ($files_names){
                            $file_name = end(explode('/', $row['name']));
                            if (!in_array($file_name, $files_names)) {
                                return $files_names;
                            }
                        }, $episodes), $files_names);

                        $data['data'][$num]['season_series'][$num_e]['series_files'] = count($files_names);
                        $this->db->updateSeries(array('series_files' => $data['data'][$num]['season_series'][$num_e]['series_files'], 'date_modify' => 'NOW()'), array('id' =>  $data['data'][$num]['season_series'][$num_e]['series_id']));
                    }
                }
                $this->db->updateSeason(array('season_series' => count($data['data'][$num]['season_series']), 'date_modify' => 'NOW()'), array('id' => $data['data'][$num]['season_id']));
            }
        }
        $data['other_files'] = array();
        if (!empty($storages['files'])) {
            $data['other_files'] = $this->fillVideoFilesData($video_id, $storages['files'], '');
        }

        $response = $this->generateAjaxResponse($data, '');

        return new Response(json_encode($response), (empty($error) ? 200 : 500));

    }

    public function get_video_files_list_json($local_uses = FALSE){
        if (!$this->isAjax && !$local_uses) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        $video_id = !empty($this->data['video_id']) ? $this->data['video_id'] : (!empty($this->postData['video_id']) ? $this->postData['video_id'] : FALSE);

        if ($video_id === FALSE) {
            return new Response(json_encode($this->generateAjaxResponse(array('action'=>'emptyFileContainer', 'success' => TRUE), '')), 200);
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();

        $params = array(
            'V_S_F.video_id' => $video_id
        );

        if (empty($_SERVER['TARGET'])) {
            $_SERVER['TARGET'] = 'ADM';
        }
        try{
            $master = new \VideoMaster();
            $storages = $master->getAllGoodStoragesForMediaFromNet($video_id, 0);
        } catch(\Exception $e) {
            $storages = array();
        }
        if (!empty($storages)) {
            $storages = call_user_func_array('array_replace_recursive', $storages);
        } else {
            $storages['tv_series'] = array();
            $storages['files'] = array();
        }

        $tv_series = $storages['tv_series'];

        $path = "";

        if (!empty($this->data['seasonnumber']) && !empty($this->data['seriesnumber'])) {
            $db_season_data = $this->db->getSeasonData(array(
                'V_S.video_id' => $video_id,
                'V_S.season_number' => $this->data['seasonnumber'],
                'V_S_S.series_number' => $this->data['seriesnumber']
            ));

            if (count($db_season_data) > 0){
                $params['V_S_F.series_id'] = $db_season_data[0]['series_id'];
            } else {
                $params['V_S_F.series_id'] = NULL;
            }
            if (isset($tv_series['seasons'][$this->data['seasonnumber']]['episodes'][$this->data['seriesnumber']][0]['name'])) {
                $path = substr($tv_series['seasons'][$this->data['seasonnumber']]['episodes'][$this->data['seriesnumber']][0]['name'], 0, strripos($tv_series['seasons'][$this->data['seasonnumber']]['episodes'][$this->data['seriesnumber']][0]['name'], '/') + 1);
            }
        } else {
            $params['V_S_F.series_id'] = NULL;
            $data['action'] = 'setUniserial';
        }

        $series_files =$this->db->getSeriesFiles($params);
        $video_rec = $this->db->getVideoById($video_id);
        if(!empty($video_rec) && $video_rec['protocol'] == 'custom' && !empty($video_rec['rtsp_url'])){
            $data['old_url'] = $video_rec['rtsp_url'];
            unset($video_rec);
        }
        $data['data'] = array();

        $quality = $this->db->getAllFromTable('quality', 'height');

        $quality = array_combine($this->getFieldFromArray($quality, 'id'), array_values($this->setLocalization($quality, 'text_title')));

        while(list($num, $row) = each($series_files)){
            $row["languages"] = unserialize($row['languages']);
            while(list($num_2, $code) = each($row["languages"])){
                $row["languages"][$num_2] = $this->getLanguageCodesEN($code);
            }

            $row["quality"] = !empty($row["quality"]) && array_key_exists($row["quality"], $quality)? $quality[$row["quality"]]["num_title"] . (!empty($quality[$row["quality"]]["text_title"]) ? ' (' . $quality[$row["quality"]]["text_title"] . ')' : ''): '';

            $tmp_path = (!empty($row['season_number']) && !empty($row['series_number'])) ? 's' . str_pad($row['season_number'], 2, '0', STR_PAD_LEFT). '/e' . str_pad($row['series_number'], 2, '0', STR_PAD_LEFT) . '/': '';
            $full_name = $tmp_path . $row['file_name'];

            if ($row["protocol"] == 'http') {
                $row["url"] = $tmp_path;
                if (!array_key_exists('all_files', $storages) || !in_array($full_name, $storages['all_files'])) {
                    $row["status"] = 0;
                }
            }

            $row['RowOrder'] = "dTRow_" . $row['season_number'] . '__' . $row['series_number'] . '_' . $num;

            $data['data'][] = $row;
        }

        if (!empty($this->data['seasonnumber']) && !empty($this->data['seriesnumber']) && isset($tv_series['seasons'][$this->data['seasonnumber']]['episodes'][$this->data['seriesnumber']])) {
            $added_files = $tv_series['seasons'][$this->data['seasonnumber']]['episodes'][$this->data['seriesnumber']];
            $data['data'] = $this->fillVideoFilesData($video_id, $added_files, $path, $data['data'], $this->data['seasonnumber'] . '_' . $this->data['seriesnumber'] . '_');
        } elseif (!isset($this->data['seasonnumber']) && !isset($this->data['seriesnumber']) && array_key_exists('files', $storages)) {
            $data['data'] = $this->fillVideoFilesData($video_id, $storages['files'], $path, $data['data']);
        }

        if (!empty($this->postData['RowOrder'])) {
            $data['RowOrder'] = $this->postData['RowOrder'];
        }

        $response = $this->generateAjaxResponse($data, '');

        if ($this->isAjax && !$local_uses) {
            $response = $this->generateAjaxResponse($response);
            return new Response(json_encode($response), (empty($error) ? 200 : 500));
        } else {
            return $response;
        }
    }

    public function save_video_files($internal_use = FALSE){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['video_id'])) {
            if (!$internal_use) {
                $this->app->abort(404, $this->setLocalization('Page not found'));
            } else {
                return $this->generateAjaxResponse();
            }
        }

        if (!$internal_use && $no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'dataTableUpdate';
        if ( array_key_exists('season_id', $this->postData) && !empty($this->postData['series_id'])) {
            $data['datatable'] = 'f_season_' . $this->postData['season_id'] . '_series_' . $this->postData['series_id'] . '_filedata';
            unset($this->postData['season_id']);
        } elseif (array_key_exists('season_id', $this->postData) && empty($this->postData['series_id'])){
            $tmp = $this->add_video_season_series(TRUE);
            $this->postData['series_id'] = $tmp['series_id'];
            $data['action'] = 'checkVideoType';
            unset($this->postData['season_id']);
        } else {
            $data['datatable'] = 'filedata';
        }

        $this->postData['languages'] = (!empty($this->postData['languages']) && is_array($this->postData['languages'])) ? serialize($this->postData['languages']): serialize(array());

        $this->postData['status'] = 1;
        if ($this->postData['file_type'] == 'sub') {
            $this->postData['quality'] = '';
        }
        $clean_old_url = FALSE;
        if (!empty($this->postData['clean_old_url'])){
            $clean_old_url = TRUE;
            unset($this->postData['clean_old_url']);
        }
        /*unset($this->postData['status']);*/

        if (!empty($params[0]['accessed'])) {
            $params[0]['accessed'] = $this->checkPublishedVideoFiles($this->postData['video_id'], (!empty($this->postData['series_id']) ? $this->postData['series_id'] : NULL), (!empty($this->postData['id']) ? $this->postData['id'] : FALSE));
        }

        if (!array_key_exists('id', $this->postData)){
            $operation = "insertSeriesFiles";
            $this->postData['date_add'] = $this->postData['date_modify'] = 'NOW()';
            $params = array($this->postData);
        } else {
            $operation = "updateSeriesFiles";
            $this->postData['date_modify'] = 'NOW()';
            $id = $this->postData['id'];
            unset($this->postData['id']);
            $params = array($this->postData, array('id' => $id));
        }

        $error = $this->setLocalization('Failed');

        if (($result = call_user_func_array(array($this->db, $operation), $params))) {
            if ($internal_use) {
                $data['id'] = isset($id) ? $id : $result;
            }
            $check_files = $this->db->getSeriesFiles(array('V_S_F.video_id' => $this->postData['video_id']), 'COUNT');
            if ($clean_old_url || !empty($check_files)){
                $this->db->updateVideo(array('protocol'=>'', 'rtsp_url' => ''), $this->postData['video_id']);
                $data['btn_old_url_remove'] = (int) $clean_old_url;
            }
            $error = '';
        } else {
            $data['msg'] = $error;
        }

        $response = $this->generateAjaxResponse($data, $error);

        return ($internal_use) ? $response :new Response(json_encode($response), (empty($error) ? 200 : 500));
    }
    
    public function get_one_video_file_json(){
        if (!$this->isAjax) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }


        $id = !empty($this->postData['id']) ? $this->postData['id'] : FALSE;
        $video_id = !empty($this->postData['video_id']) ? $this->postData['video_id'] : FALSE;
        $season_id = isset($this->postData['season_id']) ? $this->postData['season_id'] : FALSE;
        $series_id = isset($this->postData['series_id']) ? $this->postData['series_id'] : FALSE;
        $season_number = !empty($this->postData['season_number']) ? $this->postData['season_number'] : FALSE;
        $series_number = !empty($this->postData['series_number']) ? $this->postData['series_number'] : FALSE;
        $file_name = !empty($this->postData['file_name']) ? $this->postData['file_name'] : FALSE;

        $data = array(
            'data' => array(),
            'action' => 'fillModalBoxFilesData'
        );

        $error = $this->setLocalization("Failed");

        $tmp = array(array());

        if ($id !== FALSE && ($tmp = $this->db->getSeriesFiles(array('V_S_F.id' => $id))) && !empty($tmp)) {
            $tmp[0]["languages"] = unserialize($tmp[0]['languages']);
            $error = '';
            $tmp[0]["season_id"] = $season_id;
            $tmp[0]["series_id"] = $series_id;
        } elseif ($video_id !== FALSE) {
            if (empty($_SERVER['TARGET'])) {
                $_SERVER['TARGET'] = 'ADM';
            }
            try{
                $master = new \VideoMaster();
                $storages = $master->getAllGoodStoragesForMediaFromNet($video_id, 0);
                $storages = call_user_func_array('array_replace_recursive', $storages);
                $files = ($season_number !== FALSE && $series_number !== FALSE) ? $storages['tv_series']['seasons'][$season_number]['episodes'][$series_number]:$storages['files'];
            } catch (\Exception $e) {
                $files = array();
            }
            $path = substr($files[0]['name'], 0, strripos($files[0]['name'], '/'));

            if ($file_name !== FALSE) {
                while (list($key, $row) = each($files)) {
                    if (array_key_exists('subtitles', $row)) {
                        foreach($row['subtitles'] as $sub_name){
                            array_push($files, array('name' => $sub_name, 'file_type' => 'sub'));
                        }
                    }
                    $pos = strripos($row['name'], '/');
                    if (trim(substr($row['name'], ($pos !== FALSE ? $pos + 1: 0 ))) == trim($file_name)) {
                        $tmp = $this->fillVideoFilesData($video_id, array($row), $path);
                    }
                }
                $tmp[0]["id"] = $id;
                $tmp[0]["season_id"] = $season_id;
                $tmp[0]["series_id"] = $series_id;
                $tmp[0]["url"] = "";
                $error = '';
            }
        }

        if (!empty($error)) {
            $data['msg'] = $this->setLocalization('Not enough data for searching series file');
        }

        $tmp[0]["volume_level"] = !empty($tmp[0]["volume_level"]) ? $tmp[0]["volume_level"]: 0;
        settype($tmp[0]["volume_level"], 'string');
        $data['data'] = $tmp[0];

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function toggle_video_accessed(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['video_id']) || empty($this->postData['id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'dataTableUpdate';
        $error = $this->setLocalization('Something wrong');
        $check = (int)$this->postData['accessed'] == 0 ? 1 : $this->checkPublishedVideoFiles($this->postData['video_id'], (!empty($this->postData['series_id']) ? $this->postData['series_id']: NULL), $this->postData['id']);

        if ($check){
            if ( !empty($this->postData['season_id']) && !empty($this->postData['series_id'])) {
                $data['datatable'] = 'f_season_' . $this->postData['season_id'] . '_series_' . $this->postData['series_id'] . '_filedata';
                unset($this->postData['season_id']);
            } else {
                $data['datatable'] = 'filedata';
            }

            if ($this->db->getSeriesFiles(array('V_S_F.id' => $this->postData['id'])) && $this->db->updateSeriesFiles(array('accessed' => $this->postData['accessed']), array('id' => $this->postData['id']))) {
                $error = '';
                $check_files = $this->db->getSeriesFiles(array('V_S_F.video_id' => $this->postData['video_id']), 'COUNT');
                if (!empty($check_files)){
                    $this->db->updateVideo(array('protocol'=>'', 'rtsp_url' => ''), $this->postData['video_id']);
                }
            } else {
                $data['msg'] = $error;
            }
        } else {
            $data['msg'] = $error = $this->setLocalization('You can not publish this entry. There are no available video file for this entry.');
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));

    }

    public function save_season_series_names(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array();
        $data['action'] = 'emptyFunction';
        $error = FALSE; //$this->setLocalization('Something wrong');
        $parse_ids = array();
        if (preg_match_all("/(\d+)/i", $this->postData['id'], $parse_ids) && !empty($parse_ids)) {
            call_user_func_array(array($this->db, (count($parse_ids[0]) == 1 ? 'updateSeason' : 'updateSeries')), array(array($this->postData['field'] => $this->postData['value'], 'date_modify' => 'NOW()'), array('id' => $parse_ids[0][count($parse_ids[0]) - 1])));
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function remove_video_data($local_use = FALSE){
        if ((!$this->isAjax || $this->method != 'POST' || empty($this->postData['id'])) && !$local_use ) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'dataTableUpdate';
        if (!empty($this->postData['RowOrder'])) {
            $data['RowOrder'] = $this->postData['RowOrder'];
            $data['action'] = 'deleteTableRow';
        }
        if ( !$local_use ){
            if ( !empty($this->postData['season_id']) && !empty($this->postData['series_id'])) {
                $data['datatable'] = 'f_season_' . $this->postData['season_id'] . '_series_' . $this->postData['series_id'] . '_filedata';
                unset($this->postData['season_id']);
            } else {
                $data['datatable'] = 'filedata';
            }
            $params = array('id' => $this->postData['id']);
        } else {
            if (is_array($this->postData['series_files_id'])) {
                $params = array('id IN ("' . implode('", "', $this->postData['series_files_id']) . '") and 1' => 1);
            } else {
                $params = array();
            }
        }

        /*
         * @todo
         * $this->db->videoLogWrite($video, 'video deleted')
         */
        $error = $this->setLocalization('Information not available');

        if (empty($this->postData['series_id'])) {
            $this->postData['series_id'] = array_unique($this->getFieldFromArray($this->db->getSeasonData(array('V_S_F.id'=>$this->postData['id'])), 'series_id'));
        }

        $result = $this->db->deleteSeriesFiles($params);
        if (is_numeric($result)) {
            $error = '';
            $data['nothing_to_do'] = TRUE;
            if ($result !== 0) {
                if (!empty($this->postData['series_id'])) {
                    if (is_array($this->postData['series_id'])) {
                        $params = array('id IN ("' . implode('", "', $this->postData['series_id']) . '") and 1' => 1);
                    } else {
                        $params = array('id' => $this->postData['series_id']);
                    }
                    $this->db->updateSeries(array('series_files' => 'IF(series_files > 0, series_files - 1, series_files)'), $params);
                }
            }
        }

        $response = $this->generateAjaxResponse($data, $error);

        return !$local_use ? new Response(json_encode($response), (empty($error) ? 200 : 500)): $response;
    }

    public function add_video_season_series($local_use = FALSE){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['season_id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'action' => 'checkVideoType'
        );
        $error = $this->setLocalization('Failed');
        
        $season_data = $this->db->getSeasonData(array('V_S.id' => $this->postData['season_id']));

        $series_number = FALSE;
        if(!is_array($season_data)){
            $season_data = array();
        } else {
            reset($season_data);
            $series_num_arr = $this->getFieldFromArray($season_data, 'series_number');
            $series_num_arr = array_unique($series_num_arr);
            $series_num_arr = array_map('intval', $series_num_arr);
            sort($series_num_arr);
            while(list($num, $row) = each($series_num_arr)) {
                if ($num + 1 != $row) {
                    $series_number = $num + 1;
                    break;
                }
            }
            $series_number = $series_number !== FALSE ? $series_number: count($series_num_arr) + 1;
        }

        $params = array(
            'season_id' => $this->postData['season_id'],
            'series_number' => $series_number,
            'series_name' => '',
            'series_original_name' => '',
            'date_add' => 'NOW()',
            'date_modify' => 'NOW()'
        );

        $video = $this->db->getVideoById($season_data[0]['video_id']);

        $path = $video['path'];

        $_SERVER['TARGET'] = 'ADM';
        $master = new \VideoMaster();

        $series_path = $path . '/s' . str_pad($season_data[0]['season_number'], 2, '0', STR_PAD_LEFT). '/e' . str_pad($params['series_number'], 2, '0', STR_PAD_LEFT);

        try {
            $master->createMediaDir($series_path);
            if (($id = $this->db->insertSeries($params)) && $this->db->updateSeason(array('season_series' => $season_data[0]['series_count'] + 1), array('id' => $this->postData['season_id']))) {
                $error = '';
                $data['series_id'] = $id;
            } else {
                $data['msg'] = $error;
            }
        } catch (\MasterException $e) {
            $moderator_storages = $master->getModeratorStorages();
            if (!empty($moderator_storages[$e->getStorageName()])) {
                $error = $this->setLocalization('Error creating the folder on moderator storage');
                $data['msg'] = $error;
            }
        }

        $response = $this->generateAjaxResponse($data, $error);

        return !$local_use ? new Response(json_encode($response), (empty($error) ? 200 : 500)): $response;
    }

    public function add_video_season(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['video_id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'action' => 'checkVideoType'
        );
        $error = $this->setLocalization('Failed');

        $season_data = $this->db->getSeasonData(array('V_S.video_id' => $this->postData['video_id']));

        $season_number = FALSE;
        if(!is_array($season_data)){
            $season_data = 1;
        } else {
            reset($season_data);
            $season_num_arr = $this->getFieldFromArray($season_data, 'season_number');
            $season_num_arr = array_unique($season_num_arr);
            $season_num_arr = array_map('intval', $season_num_arr);
            sort($season_num_arr);

            while(list($num, $row) = each($season_num_arr)) {
                if ($num + 1 != $row) {
                    $season_number = $num + 1;
                    break;
                }
            }
            $season_data = $season_number !== FALSE ? $season_number: count($season_num_arr) + 1;
        }


        if (empty($this->postData['season_structure'])) {
            $season_structure = array($season_data => 1);
        } else {
            $season_structure = $this->postData['season_structure'];
        }

        $season_id = TRUE;
        $series_params = array(
            'series_name' => '',
            'series_original_name' => '',
            'date_add' => 'NOW()',
            'date_modify' => 'NOW()'
        );
        $season_params = array(
            'video_id' => $this->postData['video_id'],
            'season_number' => $season_data,
            'season_name' => '',
            'season_original_name' => '',
            'date_add' => 'NOW()',
            'date_modify' => 'NOW()'
        );

        $video = $this->db->getVideoById($this->postData['video_id']);

        $path = $video['path'];

        $_SERVER['TARGET'] = 'ADM';
        $master = new \VideoMaster();

        foreach ($season_structure as $season => $series) {
            $season_params['season_number'] = $season;
            $season_params['season_series'] = $series;
            $season_path = $path . '/s' . str_pad($season, 2, '0', STR_PAD_LEFT);
            try {
                $master->createMediaDir($season_path);
                if ($season_id !== FALSE && ($season_id = $this->db->insertSeason($season_params))){
                    for ($i = 1; $i <= (int) $series; $i++) {
                        $series_path = $season_path . '/e' . str_pad($i, 2, '0', STR_PAD_LEFT);
                        try {
                            $master->createMediaDir($series_path);
                            if (!$season_id || !$this->db->insertSeries(array_merge($series_params, array('season_id' => $season_id, 'series_number' => $i)))) {
                                $data['msg'] = $error;
                                $season_id = FALSE;
                                break;
                            }
                        } catch (\MasterException $e) {
                            $moderator_storages = $master->getModeratorStorages();
                            if (!empty($moderator_storages[$e->getStorageName()])) {
                                $season_id = FALSE;
                                $error = $this->setLocalization('Error creating the folder on moderator storage');
                                $data['msg'] = $error;
                                break;
                            }
                        }
                    }
                } else {
                    $data['msg'] = $error;
                    $season_id = FALSE;
                    break;
                }
            } catch (\MasterException $e) {
                $moderator_storages = $master->getModeratorStorages();
                if (!empty($moderator_storages[$e->getStorageName()])) {
                    $season_id = FALSE;
                    $error = $this->setLocalization('Error creating the folder on moderator storage');
                    $data['msg'] = $error;
                    break;
                }
            }
        }
        
        if ($season_id !== FALSE) {
            $error = '';
        }
        
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function get_media_info_json(){
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['video_id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'action' => 'setMediaInfo',
            'data' => array()
        );
        /*$error = $this->setLocalization('Failed');*/

        $error = '';
        $url = '';
        $id = 0;
        $video_id = intval($this->postData['video_id']);
        if (!empty($this->postData['id'])) {
            $id = intval($this->postData['id']);
        } elseif (empty($this->postData['url'])) {
            $tmp = $this->save_video_files(TRUE);
            $data['data']['datatable'] = !empty($tmp['datatable']) ? $tmp['datatable']: '';
            $id = !empty($tmp['id']) ? $tmp['id'] : $id;
        }

        if (!empty($this->postData['url'])) {
            $url = $this->postData['url'];
        } else {
            ob_start();
            try{
                $user = \User::getInstance(-1);
                $master = new \VideoMaster();
                $res = $master->play($video_id, 0, true, '', $id);
                $url = $res['cmd'];
            } catch (\Exception $e){
                $error = $this->setLocalization('Failed') . '. ' . $e->getMessage();
            }
            $buffer = ob_get_contents();
            $error .= empty($buffer) ? '': $buffer;
            ob_end_clean();
        }

        if (!empty($url) && empty($error)) {
            $url = end(explode(' ', trim($url)));

            if (!empty($url)) {
                try{
                    $video = \FFMpeg\FFProbe::create();
                    $lang_iso = $this->db->getAllFromTable('languages');
                    $lang_iso = array_combine($this->getFieldFromArray($lang_iso, 'iso_639_3_code'), array_values($lang_iso));
                    foreach($video->streams($url) as $rec){
                        switch ($rec->get('codec_type')) {
                            case 'video' : {
                                $data['data']['width'] = $rec->get('width');
                                $data['data']['height'] = $rec->get('height');
                                break;
                            }
                            case 'audio' : {
                                $tags = $rec->get('tags');
                                if (!empty($tags['language'])) {
                                    if (is_string($tags['language']) && strlen($tags['language']) == 3) {
                                        $data['data']['languages'][] = $lang_iso[$tags['language']]['iso_639_code'];
                                    } else {
                                        $data['data']['languages'][] = $tags['language'];
                                    }
                                }
                                break;
                            }
                        }
                    }
                } catch(\Exception $e){
                    if (class_exists('\FFMpeg\FFProbe') && !empty($video)) {
                        $error = $this->setLocalization('Failed') . '. ' . $e->getMessage();
                    } else {
                        $error = $this->setLocalization('Failed') . '. ' . $this->setLocalization('Unable to load FFProbe library. Please install "ffmpeg" or other package with this library(eg "libav-tools")');
                    }
                }

            }
        }
        if (!empty($data['data']['height'])) {
            $data['data']['quality'] = 0;
            foreach ($this->db->getAllFromTable('quality', array('height' => 'DESC')) as $row) {
                if ((int) $data['data']['height'] <= (int) $row['height'] || $data['data']['height'] == 0) {
                    $data['data']['quality'] = $row['id'];
                } else {
                    break;
                }
            }
        }

        $data['msg'] = $error;
        $data['id'] = $id;
        $data['url'] = $url;

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));

    }

    public function delete_video_season(){

        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData['season_id'])) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'action' => 'checkVideoType'
        );
        $error = $this->setLocalization('Failed');

        $season_data = $this->db->getSeasonData(array('V_S.id' => $this->postData['season_id']));

        if (!empty($season_data)) {
            $this->postData['series_id'] = array_unique($this->getFieldFromArray($season_data, 'series_id'));
            $this->postData['series_files_id'] = array_unique($this->getFieldFromArray($season_data, 'series_files_id'));
            $data = array_merge_recursive($data, $this->delete_video_season_series(TRUE));
        }

        $result = $this->db->deleteSeason(array('id' => $this->postData['season_id']));
        if (is_numeric($result)) {
            $error = '';
            if ($result === 0) {
                $data['nothing_to_do'] = TRUE;
            }
            $data = array_merge_recursive($data, $this->delete_video_season_series(TRUE));
            $this->checkResponse($data);
            $data['action'] = 'checkVideoType';
        }

        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    public function delete_video_season_series($local_use = FALSE){
        if ((!$this->isAjax || $this->method != 'POST' || empty($this->postData['series_id'])) && !$local_use ) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }

        $data = array(
            'action' => 'checkVideoType'
        );
        $error = $this->setLocalization('Failed');

        if (is_array($this->postData['series_id'])) {
            $params = array('id IN ("' . implode('", "', $this->postData['series_id']) . '") and 1' => 1);
        } elseif(is_numeric($this->postData['series_id'])) {
            $params = array('id' => $this->postData['series_id']);
        } else {
            $params = array();
        }

        $result = $this->db->deleteSeries($params);
        if (is_numeric($result)) {
            $error = '';
            if ($result === 0) {
                $data['nothing_to_do'] = TRUE;
            } else {
                $this->db->updateSeason(array('season_series' => 'IF(season_series > 0, season_series - 1, season_series)'), $params);
            }
            if (empty($this->postData['series_files_id'])) {
                reset($params);
                while(list($key, $val) = each($params)){
                    if (strpos($key, 'V_S_S') === FALSE) {
                        $params['V_S_S.'.$key] = $val;
                        unset($params[$key]);
                    }
                }
                $season_data = $this->db->getSeasonData($params);
                $this->postData['series_files_id'] = array_unique($this->getFieldFromArray($season_data, 'series_files_id'));
            }
            $data = array_merge_recursive($data, $this->remove_video_data(TRUE));
            $this->checkResponse($data);
            $data['action'] = 'checkVideoType';
        }

        $response = $this->generateAjaxResponse($data, $error);

        return !$local_use ? new Response(json_encode($response), (empty($error) ? 200 : 500)): $response;
    }

    public function watched_settings_save() {
        if (!$this->isAjax || $this->method != 'POST' || empty($this->postData)) {
            $this->app->abort(404, $this->setLocalization('Page not found'));
        }

        if ($no_auth = $this->checkAuth()) {
            return $no_auth;
        }
        $data = array();
        $data['action'] = 'JSSuccessModalBox';

        $error = '!';

        $input_data = explode('_', $this->postData['form']['history_option']);
        $input_data[] = $this->postData['form']['not_ended_history_size'];
        $params = array_combine(array('enable_not_ended', 'enable_watched', 'not_ended_history_size'), $input_data);
        $data['data'] = $params;

        try{
            $result = $this->db->saveWatchedSettings($params);
            $error = '';
        } catch (\Exception $e) {
            $error = $this->setLocalization('DB error');
            $result = FALSE;
        }

        if (is_numeric($result)) {
            if ($result === 0) {
                $data['nothing_to_do'] = TRUE;
            }
        }
        $response = $this->generateAjaxResponse($data, $error);

        return new Response(json_encode($response), (empty($error) ? 200 : 500));
    }

    //------------------------ service method ----------------------------------

    private function getVideoListFilters()
    {
        $filters = array();
        if (array_key_exists('filters', $this->data)) {

            if (array_key_exists('status_id', $this->data['filters']) && $this->data['filters']['status_id'] != 0) {
                if ($this->data['filters']['status_id'] != 3) {
                    $filters['`accessed`'] = $this->data['filters']['status_id'] - 1;
                } else {
                    $filters["not isnull(`video_on_tasks`.`id`) and '1'"] = '1';
                }
            }

            if (array_key_exists('year', $this->data['filters']) && $this->data['filters']['year'] != 0) {
                $filters["`year`"] = $this->data['filters']['year'];
            }

            if (array_key_exists('genre_id', $this->data['filters']) && $this->data['filters']['genre_id'] != 0) {
                $genre_id = $this->data['filters']['genre_id'];
                $filters["(`cat_genre_id_1` in ($genre_id) OR `cat_genre_id_2` in ($genre_id) OR `cat_genre_id_3` in ($genre_id) OR `cat_genre_id_4` in ($genre_id)) AND 1"] = "1";
            }

            if (array_key_exists('category_id', $this->data['filters']) && $this->data['filters']['category_id'] != 0) {
                $filters["media_category.id"] = $this->data['filters']['category_id'];
            }

            $this->app['filters'] = $this->data['filters'];
        } else {
            $this->app['filters'] = array();

        }

        return $filters;
    }
 
    private function buildForm($data = array()) {

        $builder = $this->app['form.factory'];

        $ages = array("0+"=>"0+","6+"=>"6+","12+"=>"12+","14+"=>"14+","16+"=>"16+","18+"=>"18+", "21+"=>"21+");
        if (is_array($data) && array_key_exists('age', $data) && empty($data['age'])) {
            unset($data['age']);
        }
        $rating_mpaa = array("G"=>"G", "PG"=>"PG", "PG-13"=>"PG-13", "R"=>"R", "NC-17"=>"NC-17");
        if (is_array($data) && array_key_exists('rating_mpaa', $data) && empty($data['rating_mpaa'])) {
            unset($data['rating_mpaa']);
        }

        /*$protocol = array('http' => "HTTP", "custom" => "Custom URL", "nfs" => "NFS");*/
        /*$genres = array();
        foreach ($this->app['videoGenres'] as $row) {
            $genres[$row['id']] = $row['title'];
        }*/

        $is_series = array_combine($this->getFieldFromArray($this->app['videoType'], 'val'), $this->getFieldFromArray($this->app['videoType'], 'title'));

        $cat_genres = array(''=>'');
        foreach ($this->app['catGenres'] as $row) {
            $cat_genres[$row['id']] = $row['category_name'];
        }
        
        $cat_video = array();
        foreach ($this->app['videoCategories'] as $row) {
            $cat_video[$row['id']] = $row['title'].$row['id'];
        }
        
        $for_sd_stb = $this->getConfigOptionalyFormField('for_sd_stb', 'vclub_mag100_filter');
        $high_quality = $this->getConfigOptionalyFormField('high_quality', 'enable_video_high_quality_option');
        $low_quality = $this->getConfigOptionalyFormField('low_quality', 'enable_video_low_quality_option');

        $form = $builder->createBuilder('form', $data)
                /*название*/
                /*+*/->add('name', 'text', array('constraints' => array( 
                            new Assert\NotBlank()),
                            'required' => TRUE
                        )
                    )
                /*+*/->add('id', 'hidden')
                /*+*/->add('rating_count_kinopoisk', 'hidden')
                /*+*/->add('rating_imdb', 'hidden')
                /*+*/->add('rating_count_imdb', 'hidden')
                /*ориг название*/
                /*+*/->add('o_name', /*'hidden'*/'text', array('required' => TRUE))
                /*кинопосик ИД*/
                /*+*/->add('kinopoisk_id', 'text', array('constraints' => array(new Assert\Type(array('type' => 'numeric'))), 'required' => FALSE))
                /*+*/->add('rating_kinopoisk', 'text', array('constraints' => array(new Assert\Type(array('type' => 'numeric'))), 'required' => FALSE))
                /*+*/->add('autocomplete_provider', 'hidden')
                /*возраст рейтинг*/
                /*+*/->add('age', 'choice', array(
                            'choices' => $ages,
                            'constraints' => array(
                                        new Assert\Choice(array('choices' => $ages))
                                    ),
                            'required' => FALSE,
                            'empty_value' => '-',
                            'empty_data'  => ''
                    )
                    )
                /*рейтинг МРАА*/
                /*+*/->add('rating_mpaa', 'choice', array(
                            'choices' => $rating_mpaa,
                            'constraints' => array(
                                        new Assert\Choice(array('choices' => $rating_mpaa))
                                    ),
                            'required' => FALSE,
                            'empty_value' => '-',
                            'empty_data'  => ''
                    )
                    )
                /*огр возраст*/
        /*+/-*/->add('censored', 'checkbox', array('required' => false))
                /*+*/->add($for_sd_stb['name'], $for_sd_stb['type'], $for_sd_stb['option'])
                /*+*/->add($high_quality['name'], $high_quality['type'], $high_quality['option'])
                /*+*/->add($low_quality['name'], $low_quality['type'], $low_quality['option'])
                /*Тип одно/много-серийный*/
                /*+*/->add('is_series', 'choice', array(
                            'choices' => $is_series,
                            'constraints' => array(
                                    new Assert\Choice(array('choices' => array_keys($is_series))
                                )
                            )
                        )
                    )
                /*категория*/
                /*+*/->add('category_id', 'choice', array(
                            'choices' => $cat_genres,
                            'constraints' => array(
                                    new Assert\Choice(array('choices' => array_keys($cat_genres))),
                                    new Assert\NotBlank()
                                ),
                            'required' => TRUE
                        )
                    )
                /*жанр*/
                /*+*/->add('cat_genre_id', 'choice', array(
                            'choices' => $cat_video,
                            'constraints' => array(
                                    new Assert\Choice(array('choices' => array_keys($cat_video), 'multiple' => TRUE)),
                                    new Assert\NotBlank()
                                ),
                            'multiple' => TRUE, 
                            'required' => TRUE
                        )
                    )
                /*год*/
                /*+*/->add('year', 'text', array(
                        'required' => FALSE,
                        'constraints' => array(
                            new Assert\Regex(array(
                                'pattern' => '/\d{4}/',
                                'match'   => TRUE))
                            )
                        )
                    )
                /*год окончания для сериалов*/
                    ->add('year_end', 'text', array(
                        'constraints' => array(
                            new Assert\Regex(array(
                                    'pattern' => '/^(?:\d{4})$/i',
                                    'match'   => TRUE))
                            ),
                        'required' => FALSE
                        )
                    )
                /*------*/->add('country', 'text', array(
                        'required' => FALSE,
                        'constraints' => array(
                                new Assert\Type(array('type' => 'string'))
                            )
                        )
                    )
                /*длительность*/
                /*+*/->add('duration', 'text', array(
                        'required' => FALSE,
                        'constraints' => array(
                                new Assert\Type(array('type' => 'numeric'))
                            )
                        )
                    )
                /*режисер*/
                /*+*/->add('director', 'text', array('required' => FALSE))
                /*актеры*/
                /*+*/->add('actors', 'textarea', array('required' => FALSE))
                /*описание*/
                /*+*/->add('description', 'textarea', array('required' => FALSE))
                /*громкость*/
                /*+*//*->add('volume_correction', 'choice', array(
                            'choices' => array_combine(range(-20, 20, 1), range(-100, 100, 5)),
                            'constraints' => array(
                                new Assert\Range(array('min' => -20, 'max' => 20)), 
                                new Assert\NotBlank()),
                            'required' => TRUE,
                            'data' => (empty($data['volume_correction']) ? 0: $data['volume_correction'])
                        )
                    )*/
                ->add('comments', 'textarea')
                /*обложка*/                
                ->add('cover_id', 'hidden')
                ->add('cover_big', 'hidden')
                ->add('save', 'submit');
//                ->add('reset', 'reset');
        return $form->getForm();
    }
    
    private function buildAdsForm($data = array()) {

        $builder = $this->app['form.factory'];
        $must_watch = array(
            'all' => $this->setLocalization('All'),
            '90' => '90%',
            '80' => '80%',
            '70' => '70%',
            '60' => '60%',
            '50' => '50%',
            '40' => '40%',
            '30' => '30%',
            '20' => '20%',
            '10' => '10%',
            '5' => '5%',
            '0' => '0%'
            );
        $form = $builder->createBuilder('form', $data)
                ->add('id', 'hidden')
                ->add('title', 'text', array('constraints' => array( 
                            new Assert\NotBlank()),
                            'required' => TRUE
                        )
                    )   
                ->add('url', 'text', array('constraints' => array( 
                            new Assert\NotBlank()),
                            'required' => TRUE
                        )
                    )
                ->add('weight', 'text', array('constraints' => array( 
                            new Assert\NotBlank(),
                            new Assert\Type(array('type' => 'numeric'))
                            ),
                            'required' => TRUE
                        )
                    )
                ->add('denied_categories', 'choice', array(
                            'choices' => $this->video_categories,
                            'constraints' => array(
                                    new Assert\Choice(array('choices' => array_keys($this->video_categories), 'multiple' => TRUE))
                                ),
                            'multiple' => TRUE, 
                            'required' => FALSE
                        )
                    )
                ->add('must_watch', 'choice', array(
                            'choices' => $must_watch,
                            'constraints' => array(
                                    new Assert\NotBlank(),
                                    new Assert\Choice(array('choices' => array_keys($must_watch), 'multiple' => FALSE))
                                ),
                            'multiple' => FALSE, 
                            'required' => TRUE
                        )
                    )
                ->add('save', 'submit');
//                ->add('reset', 'reset');

        return $form->getForm();
    }
    
    private function buildModForm($data = array()) {

        $builder = $this->app['form.factory'];

        $form = $builder->createBuilder('form', $data)
                ->add('id', 'hidden')
                ->add('name', 'text', array('constraints' => array( 
                            new Assert\NotBlank()),
                            'required' => TRUE
                        )
                    )   
                ->add('mac', 'text', array('constraints' => array( 
                                                new Assert\NotBlank(),
                                                new Assert\Regex('/([0-9a-fA-F]{2}([:]|$)){6}$/')
                                            ),
                                            'required' => TRUE
                                        )
                    )
                ->add('disable_vclub_ad', 'checkbox', array('required' => FALSE))
                ->add('save', 'submit');
//                ->add('reset', 'reset');

        return $form->getForm();
    }
    
    private function getConfigOptionalyFormField($field_name, $config_option){
        $return_opt = array(
            'name' => $field_name,
            'type' => 'hidden',
            'option' => array()
        );
        if (Config::getSafe($config_option, false)){
            $return_opt['type'] = 'checkbox';
            $return_opt['option'] = array('required' => false);
        }
        return $return_opt;
    }
    
    private function saveVideoData(&$form, $edit = FALSE) {
        if (!empty($this->method) && $this->method == 'POST') {
            $form->handleRequest($this->request);
            $data = $form->getData();

            if ($form->isValid()) {
                if (empty($data['id'])) {
                    $is_repeating_name = $this->db->checkName($data);
                    $operation = 'insertVideo';
                } elseif (isset($this->oneVideo)) {
                    $check_name = (bool) $this->db->checkName(array('id<>'=>$data['id'], 'name' => $data['name']));
                    $is_repeating_name = empty($this->oneVideo['name']) || $check_name;
                    $operation = 'updateVideo';
                }
                if (!$is_repeating_name) {
                    $series = array();
                    if (!empty($data['protocol']) && $data['protocol'] == 'custom') {
                        if (empty($data['rtsp_url'])) {
                            $error_local = array();
                            $error_local['rtsp_url'] = ($is_repeating_name ? $this->setLocalization('If the protocol') . " - '$data[protocol]', " . $this->setLocalization('then this field must be filled') : '');
                            $this->app['error_local'] = $error_local;
                            return FALSE;
                        } else {
                            if (empty($data['id'])) {
                                if (preg_match("/s\d+e(\d+).*$/i", $data['rtsp_url'], $tmp_arr)) {
                                    $series = range(1, (int)$tmp_arr[1], 1);
                                }
                            } else {
                                $this->data['video_id'] = $data['id'];
                                $check_files = $this->db->getSeriesFiles(array('V_S_F.video_id' => $this->data['video_id']), 'COUNT');
                                if (!empty($check_files)) {
                                    $this->db->updateVideo(array('protocol'=>'', 'rtsp_url' => ''), $data['id']);
                                }
                            }
                        }
                    }
                    $data['trans_name'] = $this->transliterate(@urldecode($data['name']));
                    if (!empty($data['hd']) && $data['hd']) {
                        $data['trans_name'] .= '_HD';
                    }

                    $db_data = array(
                        'name' => trim($data['name']),
                        'series' => serialize($series),
                        'o_name' => trim($data['o_name']),
                        'censored' => $data['censored'],
                        /*'hd' => $data['hd'],*/
                        'for_sd_stb' => $data['for_sd_stb'],
                        'protocol' => !empty($data['protocol']) ? $data['protocol']: '',
                        'rtsp_url' => !empty($data['rtsp_url']) ? $data['rtsp_url']: '',
                        'time' => !empty($data['duration']) ? $data['duration']: '',
                        'description' => !empty($data['description']) ? $data['description']: '',
                        'genre_id_1' => (!empty($data['genres']) && array_key_exists(0, $data['genres']) ? $data['genres'][0] : 0),
                        'genre_id_2' => (!empty($data['genres']) && array_key_exists(1, $data['genres']) ? $data['genres'][1] : 0),
                        'genre_id_3' => (!empty($data['genres']) && array_key_exists(2, $data['genres']) ? $data['genres'][2] : 0),
                        'genre_id_4' => (!empty($data['genres']) && array_key_exists(3, $data['genres']) ? $data['genres'][3] : 0),
                        'cat_genre_id_1' => (array_key_exists(0, $data['cat_genre_id']) ? $data['cat_genre_id'][0] : 0),
                        'cat_genre_id_2' => (array_key_exists(1, $data['cat_genre_id']) ? $data['cat_genre_id'][1] : 0),
                        'cat_genre_id_3' => (array_key_exists(2, $data['cat_genre_id']) ? $data['cat_genre_id'][2] : 0),
                        'cat_genre_id_4' => (array_key_exists(3, $data['cat_genre_id']) ? $data['cat_genre_id'][3] : 0),
                        'category_id' => $data['category_id'],
                        'director' => !empty($data['director']) ? $data['director']: '',
                        'actors' => !empty($data['actors']) ? $data['actors']: '',
                        'status' => 1,
                        'year' => !empty($data['year']) ? $data['year']: '',
                        'year_end' => !empty($data['year_end']) ? $data['year_end']: '',
                        'volume_correction' => array_key_exists('genres', $data) ? (int)$data['volume_correction']: 0,
                        'kinopoisk_id' => $data['kinopoisk_id'],
                        'rating_kinopoisk' => $data['rating_kinopoisk'],
                        'rating_count_kinopoisk' => $data['rating_count_kinopoisk'],
                        'rating_imdb' => $data['rating_imdb'],
                        'rating_count_imdb' => $data['rating_count_imdb'],
                        'age' => $data['age'],
                        'rating_mpaa' => $data['rating_mpaa'],
                        'high_quality' => $data['high_quality'],
                        'low_quality' => $data['low_quality'],
                        'comments' => $data['comments'],
                        'country' => !empty($data['country']) ? $data['country']: '',
                        'is_series' => $data['is_series'],
                        'autocomplete_provider' => !empty($data['autocomplete_provider']) ? $data['autocomplete_provider']: NULL
                    );
                    if ($operation == 'insertVideo') {
                        $this->createMediaStorage($data['trans_name'], $data['year']);
                        $db_data['path'] = $data['trans_name'] . (!empty($data['year']) ? "_$data[year]": '');
                        $db_data['added'] = 'NOW()';
                        $id = $this->db->$operation($db_data);
                        $db_data['id'] = $id;
                        $this->db->videoLogWrite($db_data, 'added');
                    } else {
                        $id = $data['id'];
                        $this->db->$operation($db_data, $id);
                        $db_data['id'] = $id;
                        $this->db->videoLogWrite($db_data, 'edited');
                    }
                    $cover_id = (!empty($data['cover_big']) ? $this->getExternalImage($data['cover_big'], $id) : (!empty($data['cover_id']) ? $data['cover_id'] : FALSE));
                    if ($cover_id !== FALSE) {
                        $this->db->updateScreenshotData($id, $cover_id);
                    }
                    $this->oneVideo['id'] = $id;
//                    $this->db->cleanScreenshotData();
                    return TRUE;
                } else {
                    $error_local = array();
                    $error_local['name'] = ($is_repeating_name ? $this->setLocalization('This name already exists') : '');
                    $this->app['error_local'] = $error_local;

                    return FALSE;
                }
            }
        }
        return FALSE;
    }

    private function prepareFormVideoCategories(){
        $videoGenres = $this->db->getVideoGenres();
        $this->app['videoGenres'] = $this->getUCArray($this->setLocalization($videoGenres, 'title'), 'title');
        
        $catGenres = $this->db->getCategoriesGenres();
        $this->app['catGenres'] = $this->getUCArray($this->setLocalization($catGenres, 'category_name'), 'category_name');
        
        $videoCategories = $this->db->getVideoCategories();
        $this->app['videoCategories'] = $this->getUCArray($this->setLocalization($videoCategories, 'title'), 'title');
        
        $this->app['videoEdit'] = FALSE;
        
        $prepared_cat_genre = array();
        foreach($this->app['videoCategories'] as $row){
            if (!array_key_exists($row['category_alias'], $prepared_cat_genre)) {
                $prepared_cat_genre[$row['category_alias']] = array();
            }
            $prepared_cat_genre[$row['category_alias']][] = $row;
        }
        
        $this->app['preparedCatGenre'] = $prepared_cat_genre;
    }
    
    private function prepareOneVideo() {
        $this->catFieldsToArray('genre_id_', "genres", 4);
        $this->catFieldsToArray('cat_genre_id_', "cat_genre_id", 4);
        $this->oneVideo['duration'] = (!empty($this->oneVideo['time'])? $this->oneVideo['time']: 0);
        $this->oneVideo['cover_id'] = $this->db->getScreenshotData(array('media_id' => $this->oneVideo['id'], 'video_episodes' => 0));
        $this->getBoolVal($this->oneVideo);
    }
    
    private function catFieldsToArray($field_prefix, $array_name, $fields_count) {
        if (empty($this->oneVideo)) {
            return;
        }
        $return_array = array();
        for($i = 1; $i <= $fields_count; $i++){
            if (array_key_exists($field_prefix.$i, $this->oneVideo) && !empty($this->oneVideo[$field_prefix.$i])) {
                $return_array[] = $this->oneVideo[$field_prefix.$i];
            }
        }
        $this->oneVideo[$array_name] = $return_array;
    }
    
    private function getBoolVal(&$data){
        while(list($key, $val) = each($data)){
            if(is_string($val) || is_numeric($val) || is_null($val)){
                $data[$key] = (empty($val)? FALSE: (intval($val) == 1? TRUE: $val));
            } /*elseif(is_array($val) || is_object($val)){
                $data[$key] = $this->getBoolVal($val);
            }*/
        }
    }
    
    private function getExternalImage($url, $video_id = FALSE, $file_num = 0) {
        $cover_id = $cover = FALSE;
        try {
            $tmpfname = tempnam("/tmp", "video_cover");
            $cover_blob = file_get_contents($url);
            file_put_contents($tmpfname, $cover_blob);
            $cover = new \Imagick($tmpfname);
            unlink($tmpfname);
        } catch (\ImagickException $e) {
            $error = $this->setLocalization('Error: ' . $e->getMessage());
        }

        if ($cover) {

            try{
                if (!$cover->resizeImage(240, 320, \Imagick::FILTER_LANCZOS, 1)) {
                    $error = $this->setLocalization('Error: could not resize cover');
                }
            } catch (\ImagickException $e) {
                $error = $this->setLocalization('Error') . ': ' . $e->getMessage();
            }

            $cover_filename = substr($url, strrpos($url, '/') + 1);
            $s_data = array(
                'name' => $cover_filename,
                'size' => $cover->getimagesize(),
                'type' => $cover->getformat(),
                'media_id' => $video_id,
                'video_episodes' => $file_num
            );
            $ext = end(explode('.', $s_data['name']));

            $screenshot = $this->db->getScreenshotData(array('media_id' => $video_id, 'video_episodes' => $file_num), 'ALL');
            
            foreach ($screenshot as $row) {
                $this->delete_cover($row['id']);
            }
            
            $cover_id = $this->db->saveScreenshotData($s_data);

            $img_path = $this->getCoverFolder($cover_id);
            umask(0);

            if (!empty($error) || empty($cover_id) || $img_path == -1) {
                $error = $this->setLocalization('Error: could not save cover image');
            } else {
                try{
                    $cover->writeImage($img_path . '/' . $cover_id . ".$ext");
                } catch (\ImagickException $e) {
                    $error = $this->setLocalization('Error') . ': ' . $e->getMessage();
                }
            }

            $cover->destroy();
        }
        return $cover_id;
    }
    
    private function createMediaStorage($trans_name, $additional = '') {

        $existed = $this->db->getVideoByParam(array('path' => $trans_name));

        if (!empty($existed)) {
            $error = $this->setLocalization('Error: The folder with that name already exists');
        } else {
            $_SERVER['TARGET'] = 'ADM';
            $master = new \VideoMaster();
            try {
                $master->createMediaDir($trans_name, $additional);
            } catch (\MasterException $e) {
                //var_dump($e->getMessage(), $e->getStorageName()); exit;
                $moderator_storages = $master->getModeratorStorages();
                if (!empty($moderator_storages[$e->getStorageName()])) {
                    $error = $this->setLocalization('Error creating the folder on moderator storage');
                }
            }
        }
    }

    private function saveVideoAdsData(&$form){
        if (!empty($this->method) && $this->method == 'POST') {
            $form->handleRequest($this->request);
            $data = $form->getData();
            $action = (isset($this->ad) ? 'updateById': 'add');

            if ($form->isValid()) {
                if ($action=='add'){
                    if ( $this->ads->$action($data)){
                        return TRUE;
                    }                    
                } else {
                    if ( $this->ads->$action($data['id'],$data)){
                        return TRUE;
                    }
                }
            
            }
        }
        return FALSE;
    }
    
    private function getVideoCatForAds(){
        $keys = $this->getFieldFromArray($this->video_categories, 'id');
        $values = $this->getFieldFromArray($this->video_categories, 'category_name');
        $this->video_categories = array_combine($keys, $values);
    }
    
    private function saveVideoModData(&$form){
        if (!empty($this->method) && $this->method == 'POST') {
            $form->handleRequest($this->request);
            $data = $form->getData();

            if (array_key_exists('disable_vclub_ad', $data)) {
                $data['disable_vclub_ad'] = (int)$data['disable_vclub_ad'];
            } else {
                $data['disable_vclub_ad'] = 0;
            }

            if (isset($this->mod)) {
                $action = 'updateModeratorsById';
                $id = $data['id'];
                unset($data['id']);
                $params = array($id, $data);
            } else {
                $action = 'insertModerators';
                $params = array($data);
            }

            $result = FALSE;

            if ($form->isValid() && ($result = call_user_func_array(array($this->db, $action), $params)) !== FALSE) {
                return TRUE;
            }
        }
        return FALSE;
    }
    
    private function setLinksForVideoLog(&$data){
        $action_link_template = "<a href='$this->workURL/tasks/task-detail-video?id={action[task]}'>{action[event]}</a>";
        $video_name_link_template = "<a href='$this->workURL/tasks/task-detail-video?id={match[0]}'>{match[1]}</a>";
        reset($data);
        while(list($key, $row) = each($data)){
            $data[$key]['video_name'] = "<a href='$this->workURL/" . $this->app['controller_alias'] . "/edit-video?id=$row[video_id]'>$row[video_name]</a>";
            if ($action = @unserialize($row['action'])) {
                $data[$key]['action'] = strtr($action_link_template, array("{action[task]}" => $action['task'], "{action[event]}" => $this->mb_ucfirst($this->setLocalization($action['event']))));
            } else {
                $matches = array();
                $c = preg_match_all("/task\=(\d*)[^\>]*\>([^\<]*)\</i", stripcslashes($row['action']), $matches);
                if (count($matches) >= 2 && !empty($matches[1][0]) && !empty($matches[2][0])) {
                    $data[$key]['action'] = strtr($action_link_template, array("{action[task]}" => $matches[1][0], "{action[event]}" => $this->mb_ucfirst($this->setLocalization($matches[2][0]))));
                } 
            }
        }
    }
    
    private function getVideoListDropdownAttribute(){
        return array(
            array('name' => 'id',           'title' => $this->setLocalization('ID'),            'checked' => TRUE),
            array('name' => 'path',         'title' => $this->setLocalization('Catalogue'),     'checked' => TRUE),
            array('name' => 'name',         'title' => $this->setLocalization('Title'),         'checked' => TRUE),
            array('name' => 'o_name',       'title' => $this->setLocalization('Original title'),'checked' => FALSE),
            array('name' => 'time',         'title' => $this->setLocalization('Length, min'),   'checked' => TRUE),
            array('name' => 'is_series',    'title' => $this->setLocalization('Serial'),        'checked' => TRUE),
            array('name' => 'cat_genre',    'title' => $this->setLocalization('Genre'),         'checked' => TRUE),
            array('name' => 'year',         'title' => $this->setLocalization('Year'),          'checked' => TRUE),
            array('name' => 'added',        'title' => $this->setLocalization('Date'),          'checked' => TRUE),
            array('name' => 'tasks',        'title' => $this->setLocalization('Tasks'),         'checked' => TRUE),
            array('name' => 'count',        'title' => $this->setLocalization('Views lifetime'),'checked' => FALSE),
            array('name' => 'counter',      'title' => $this->setLocalization('Views last month'),'checked' => FALSE),
            array('name' => 'complaints',   'title' => $this->setLocalization('Complaints'),    'checked' => TRUE),
            array('name' => 'accessed',     'title' => $this->setLocalization('Status'),        'checked' => TRUE),
            array('name' => 'operations',   'title' => $this->setLocalization('Operations'),    'checked' => TRUE)
        );
        
    }

    private function getVideoScheduleDropdownAttribute(){
        return array(
            array('name' => 'task_added',   'title' => $this->setLocalization('Date'),          'checked' => TRUE),
            array('name' => 'name',         'title' => $this->setLocalization('Title'),         'checked' => TRUE),
            array('name' => 'o_name',       'title' => $this->setLocalization('Original title'),'checked' => TRUE),
            array('name' => 'time',         'title' => $this->setLocalization('Length, min'),   'checked' => TRUE),
            array('name' => 'task_date_on', 'title' => $this->setLocalization('Date of publication'),'checked' => TRUE),
            array('name' => 'year',         'title' => $this->setLocalization('Year'),          'checked' => TRUE),
            array('name' => 'operations',   'title' => $this->setLocalization('Operations'),    'checked' => TRUE)
        );
    }

    private function getVideoAdvertiseDropdownAttribute(){
        return array(
            array('name' => 'title',        'title' => $this->setLocalization("Title"),                     'checked' => TRUE),
            array('name' => 'url',          'title' => $this->setLocalization("Address"),                   'checked' => TRUE),
            array('name' => 'weight',       'title' => $this->setLocalization("Weight"),                    'checked' => TRUE),
            array('name' => 'started',      'title' => $this->setLocalization("Views started"),             'checked' => TRUE),
            array('name' => 'ended',        'title' => $this->setLocalization("Views counted"),             'checked' => TRUE),
            array('name' => 'must_watch',   'title' => $this->setLocalization("Necessary to view")." (%)",  'checked' => TRUE),
            array('name' => 'status',       'title' => $this->setLocalization("Status"),                    'checked' => TRUE),
            array('name' => 'operations',   'title' => $this->setLocalization("Operations"),                'checked' => TRUE)
        );
    }

    private function getVideoAdvertiseFields(){
        return array(
            'id'         => 'V_A.`id` as `id`',
            'title'         => 'V_A.`title` as `title`',
            'url'           => 'V_A.`url` as `url`',
            'weight'        => 'V_A.`weight` as `weight`',
            'started'       => 'CAST(SUM(V_A_L.`watch_complete`) as UNSIGNED) as `started`',
            'ended'         => 'CAST(COUNT(V_A_L.`vclub_ad_id`) as UNSIGNED) as `ended`',
            'must_watch'    => 'V_A.`must_watch` as `must_watch`',
            'status'        => 'V_A.`status` as `status`'
        );
    }

    private function prepareNewGenresListIds($all_genre_list = array()){
        $all_genre_list = $this->setLocalization($all_genre_list, 'title');
        $return_list = array();
        foreach($all_genre_list as $row){
            $row["title"] = $this->mb_ucfirst($row["title"]);
            if (array_key_exists($row['title'], $return_list)) {
                $return_list["$row[title]"]['id'] .= ",$row[id]";
            } else {
                $return_list["$row[title]"] = array('id'=>$row['id'], 'title' => $row["title"]);
            }
        }
        ksort($return_list);
        return array_combine(range(0, count($return_list)-1), array_values($return_list));
    }

    private function getVideoCategoriesDropdownAttribute(){
        return array(
            array('name'=>'num',            'title'=>$this->setLocalization('Number'),          'checked' => TRUE),
            array('name'=>'category_name',  'title'=>$this->setLocalization('Title'),           'checked' => TRUE),
            array('name'=>'localized_title','title'=>$this->setLocalization('Localized title'), 'checked' => TRUE),
            array('name'=>'genre_in_category',  'title'=>$this->setLocalization('Genres in category'), 'checked' => TRUE),
            array('name'=>'movie_in_category',  'title'=>$this->setLocalization('Movies in category'), 'checked' => TRUE),
            array('name'=>'censored',           'title'=>$this->setLocalization('Age restriction'), 'checked' => TRUE),
            array('name'=>'operations',     'title'=>$this->setLocalization('Operation'),       'checked' => TRUE)
        );
    }

    private function getVideoGenresDropdownAttribute(){
        return array(
            array('name'=>'title',          'title'=>$this->setLocalization('Title'),           'checked' => TRUE),
            array('name'=>'localized_title','title'=>$this->setLocalization('Localized title'), 'checked' => TRUE),
            array('name'=>'category',       'title'=>$this->setLocalization('Category'),        'checked' => TRUE),
            array('name'=>'movie_in_genre', 'title'=>$this->setLocalization('Movies in genre'), 'checked' => TRUE),
            array('name'=>'operations',     'title'=>$this->setLocalization('Operation'),       'checked' => TRUE)
        );
    }

    private function check_video_status($id){

        $video = \Video::getById($id);

        if (!empty($video['rtsp_url'])){
            return 2;
        }

        return $video['status'];
    }

    private function getVideoScheduleFields(){
        return array(
            'task_added' => 'video_on_tasks.added as `task_added`',
            'name' => 'video.name as `name`',
            'o_name' => 'video.o_name as `o_name`',
            'time' => 'video.time as `time`',
            'tasks' => '"" as `tasks`',
            'year' => 'video.year as `year`',
            'task_id' => 'video_on_tasks.id as `task_id`',
            'video_id' => 'video_on_tasks.video_id as `video_id`',
            'task_date_on' => "UNIX_TIMESTAMP(`video_on_tasks`.`date_on`) as `task_date_on`",
            'id' => 'video_on_tasks.id as `id`'
        );
    }

    private function getVideoModeratorsAddressesDropdownAttribute(){
        return array(
            array('name'=>'name',           'title'=>$this->setLocalization('Name'),        'checked' => TRUE),
            array('name'=>'mac',            'title'=>$this->setLocalization('MAC address'), 'checked' => TRUE),
            array('name'=>'disable_vclub_ad','title'=>$this->setLocalization('Advertising is disabled'),'checked' => TRUE),
            array('name'=>'status',         'title'=>$this->setLocalization('Status'),      'checked' => TRUE),
            array('name'=>'operations',     'title'=>$this->setLocalization('Operation'),   'checked' => TRUE)
        );
    }

    private function getVideoModeratorsAddressesFields(){
        return array(
            'id' => 'moderators.id as `id`',
            'name' => 'moderators.name as `name`',
            'mac' => 'moderators.mac as `mac`',
            'disable_vclub_ad' => 'moderators.disable_vclub_ad as `disable_vclub_ad`',
            'status' => 'moderators.status as `status`'
        );
    }

    private function getVideoLogsDropdownAttribute(){
        return array(
            array('name'=>'video_id',           'title'=>$this->setLocalization('ID'),        'checked' => TRUE),
            array('name'=>'actiontime',            'title'=>$this->setLocalization('Date'), 'checked' => TRUE),
            array('name'=>'video_name','title'=>$this->setLocalization('Title'),'checked' => TRUE),
            array('name'=>'login',         'title'=>$this->setLocalization('Moderator'),      'checked' => TRUE),
            array('name'=>'action',     'title'=>$this->setLocalization('Status'),   'checked' => TRUE)
        );
    }

    private function getVideoFilesDropdownAttribute(){
        return array(
            array('name' => 'file_type',    'title' => $this->setLocalization('Type'),          'checked' => TRUE),
            array('name' => 'protocol',     'title' => $this->setLocalization('Protocol'),      'checked' => TRUE),
            array('name' => 'url',          'title' => $this->setLocalization('Address'),       'checked' => TRUE),
            array('name' => 'file_name',    'title' => $this->setLocalization('File name'),     'checked' => TRUE),
            array('name' => 'languages',    'title' => $this->setLocalization('Language'),      'checked' => TRUE),
            array('name' => 'quality',      'title' => $this->setLocalization('Quality'),       'checked' => TRUE),
            array('name' => 'volume_level', 'title' => $this->setLocalization('Volume level'),  'checked' => TRUE),
            array('name' => 'status',       'title' => $this->setLocalization('Status'),        'checked' => TRUE),
            array('name' => 'operations',   'title' => $this->setLocalization('Operation'),     'checked' => TRUE)
        );

    }

    private function fillVideoFilesData($video_id, $added_files, $path, $return_data = array(), $post_fix = ''){
        while(list($num, $row) = each($added_files)) {
            if (array_key_exists('subtitles', $row)) {
                foreach($row['subtitles'] as $sub_name){
                    array_push($added_files, array('name' => $sub_name, 'file_type' => 'sub'));
                }
            }
            $files["video_id"] = $video_id;
            $files["file_type"] = (array_key_exists('file_type', $row) ? $row['file_type']: '');
            $files["protocol"] = 'http';
            $files["url"] = $path;
            $delimeter_pos = strripos($row['name'], '/');
            $files["file_name"] = substr($row['name'], ($delimeter_pos !== FALSE ? $delimeter_pos + 1: 0), strlen($row['name']));
            $files["languages"] = array();
            $files["quality"] = '';
            $files["volume_level"] = 0;
            $files["accessed"] = 0;
            $files["status"] = 1;
            $files['RowOrder'] = "dTRow_" . $post_fix . $num;

            if (!empty($return_data)) {
                $files_exists = array_map(function($data_row) use ($files) {
                    return (trim($files['file_name']) == trim($data_row['file_name']) ? 1: 0 );
                }, $return_data);
                if (array_sum($files_exists)) {
                    $files = array();
                }
            }

            if (!empty($files)) {
                $return_data[]= $files;
            }
        }
        return $return_data;
    }

    private function getVideoCategoryFields(){
        return array(
            'num' => '`media_category`.`num` as `num`',
            'category_name' => '`media_category`.`category_name` as `category_name`',
            'genre_in_category' => 'CAST((SELECT  COUNT(*) FROM `cat_genre` WHERE `cat_genre`.`category_alias` = `media_category`.`category_alias`) as CHAR) as `genre_in_category`',
            'movie_in_category' => 'CAST((SELECT  COUNT(*) FROM `video` WHERE `video`.`category_id` = `media_category`.`id`) as CHAR) as `movie_in_category`',
            'censored' => '`media_category`.`censored` as `censored`',
        );
    }

    private function getVideoCategoryGenresFields(){
        return array(
            'title' => '`cat_genre`.`title` as `title`',
            'category' => '`media_category`.`category_name` as `category`',
            'movie_in_genre' => '(SELECT  COUNT(*) FROM `video` WHERE `video`.`category_id` = `media_category`.`id` AND (`cat_genre`.`id` = `video`.`cat_genre_id_1` || `cat_genre`.`id` = `video`.`cat_genre_id_2` || `cat_genre`.`id` = `video`.`cat_genre_id_3` || `cat_genre`.`id` = `video`.`cat_genre_id_4`)) as `movie_in_genre`',
            'id' => '`cat_genre`.`id` as `id`',
            'category_id' => '`media_category`.`id` as `category_id`',
            'category_name' =>  '`media_category`.`category_name` as `category_name`'
        );
    }

    private function checkResponse(&$response){
        if (is_array($response)) {
            foreach (array('error', 'success', 'nothing_to_do', 'msg') as $key) {
                if (array_key_exists($key, $response) && is_array($response[$key])) {
                    list($key1, $val) = each($response[$key]);
                    $response[$key] = is_bool($val) ? (bool)array_product($response[$key]) : (((string)$val) != ''  ? implode('. <br> ', $response[$key]) : '');
                }
            }
            unset($response['action']);
        }
        return $response;
    }

    private function checkPublishedVideoFiles($video_id, $series_id = 'all', $file_id = FALSE){
        $params = array(
            'V_S_F.video_id' => $video_id
        );

        if ($file_id !== FALSE) {
            $params["IF(V_S_F.file_type = 'video', IF(V_S_F.id = '$file_id', 1, V_S_F.accessed = 1 and V_S_F.status = 1), 0) and 1"] = 1;
        } else {
            if ($series_id != 'all') {
                $params['V_S_F.series_id'] = $series_id;
            } else {
                $params["V_S_F.file_type"] = 'video';
                $params["V_S_F.accessed"] = 1;
                $params["V_S_F.status"] = 1;
            }
        }

        return (bool) $this->db->getSeriesFiles($params, 'COUNT');
    }

    private function buildWatchedSettingsForm($data = array()) {

        $builder = $this->app['form.factory'];

        $history_option = array(
            '1_1' => $this->setLocalization("Yes"),
            '0_0' => $this->setLocalization("No"),
            '0_1' => $this->setLocalization("Only not ended")
        );

        $add_label_str = ' (' . implode(', ', $this->setLocalization(array('movie', 'episode', 'serial', 'quality' ))) . ')';

        $not_ended_history_size = array(
            0 => $this->setLocalization("All history"),
            365 => $this->setLocalization("1 year") . $add_label_str,
            180 => $this->setLocalization("6 months") . $add_label_str,
            90 => $this->setLocalization("3 months") . $add_label_str,
            30 => $this->setLocalization("1 month") . $add_label_str,
        );

        if (!empty($data)) {
            $data['history_option'] = $data['enable_not_ended'] . '_' . $data['enable_watched'];
            unset($data['enable_not_ended']);
            unset($data['enable_watched']);
            unset($data['id']);
        }

        $form = $builder->createBuilder('form', $data);
        $form->add('history_option', 'choice', array(
            'choices'   => $history_option,
            'attr' => array('class' => 'radio'),
            'label' => $this->setLocalization('Save viewing history'),
            'expanded' => TRUE,
            'multiple' => FALSE,
            'required' => TRUE
        ))->add('not_ended_history_size', 'choice', array(
            'choices'   => $not_ended_history_size,
            'attr' => array('class' => 'radio'),
            'label' => $this->setLocalization('History size'),
            'expanded' => TRUE,
            'multiple' => FALSE,
            'required' => TRUE
        ))->add('save', 'submit');

        return $form->getForm();
    }
}
