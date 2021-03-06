<?php
/**
 * *
 *  * ============================================================================
 *  * Created by PhpStorm.
 *  * User: Ice
 *  * 邮箱: ice@sbing.vip
 *  * 网址: https://sbing.vip
 *  * Date: 2019/9/19 下午3:33
 *  * ============================================================================.
 */

namespace app\admin\controller;

use fast\Http;
use think\Exception;
use think\AddonService;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Filesystem;
use app\common\controller\Backend;

/**
 * 插件管理.
 *
 * @icon   fa fa-cube
 * @remark 可在线安装、卸载、禁用、启用插件，同时支持添加本地插件。YFCMF-TP6已上线插件商店 ，你可以发布你的免费或付费插件：<a href="https://www.iuok.cn/store.html" target="_blank">https://www.iuok.cn/store.html</a>
 */
class Addon extends Backend
{
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        if (!$this->auth->isSuperAdmin() && in_array($this->request->action(), ['install', 'uninstall', 'local', 'upgrade'])) {
            $this->error(__('Access is allowed only to the super management group'));
        }
    }

    /**
     * 查看.
     */
    public function index()
    {
        $addons = get_addon_list();
        foreach ($addons as $k => &$v) {
            $config = get_addon_config($v['name']);
            $v['config'] = $config ? 1 : 0;
            $v['url'] = str_replace($this->request->server('SCRIPT_NAME'), '', $v['url']);
        }
        $this->assignconfig(['addons' => $addons]);

        return $this->view->fetch();
    }

    /**
     * 配置.
     */
    public function config($name = null)
    {
        $name = $name ? $name : $this->request->get("name");
        if (! $name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        if (! preg_match('/^[a-zA-Z0-9]+$/', $name)) {
            $this->error(__('Addon name incorrect'));
        }
        if (! is_dir(ADDON_PATH.$name)) {
            $this->error(__('Directory not found'));
        }
        $info = get_addon_info($name);
        $config = get_addon_fullconfig($name);
        if (! $info) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a", [], 'trim');
            if ($params) {
                foreach ($config as $k => &$v) {
                    if (isset($params[$v['name']])) {
                        if ($v['type'] == 'array') {
                            $params[$v['name']] = is_array($params[$v['name']]) ? $params[$v['name']] : (array) json_decode($params[$v['name']],
                                true);
                            $value = $params[$v['name']];
                        } else {
                            $value = is_array($params[$v['name']]) ? implode(',',
                                $params[$v['name']]) : $params[$v['name']];
                        }
                        $v['value'] = $value;
                    }
                }

                try {
                    //更新配置文件
                    set_addon_fullconfig($name, $config);
                    AddonService::refresh();
                    $this->success();
                } catch (Exception $e) {
                    $this->error(__($e->getMessage()));
                }
            }
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $tips = [];
        foreach ($config as $index => &$item) {
            if ($item['name'] == '__tips__') {
                $tips = $item;
                unset($config[$index]);
            }
        }
        $this->view->assign('addon', ['info' => $info, 'config' => $config, 'tips' => $tips]);
        $configFile = ADDON_PATH.$name.DIRECTORY_SEPARATOR.'config.html';
        $viewFile = is_file($configFile) ? $configFile : '';

        return $this->view->fetch($viewFile);
    }

    /**
     * 安装.
     */
    public function install()
    {
        $name = $this->request->post('name');
        $force = (int) $this->request->post('force');
        if (! $name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        if (! preg_match('/^[a-zA-Z0-9]+$/', $name)) {
            $this->error(__('Addon name incorrect'));
        }

        try {
            $uid = $this->request->post('uid');
            $token = $this->request->post('token');
            $version = $this->request->post('version');
            $faversion = $this->request->post('faversion');
            $extend = [
                'uid'       => $uid,
                'token'     => $token,
                'version'   => $version,
                'faversion' => $faversion,
            ];
            AddonService::install($name, $force, $extend);
            $info = get_addon_info($name);
            $info['config'] = get_addon_config($name) ? 1 : 0;
            $info['state'] = 1;
            $this->success(__('Install successful'), null, ['addon' => $info]);
        } catch (Exception $e) {
            $this->error(__($e->getMessage()), $e->getCode());
        }
    }

    /**
     * 卸载.
     */
    public function uninstall()
    {
        $name = $this->request->post('name');
        $force = (int) $this->request->post('force');
        if (! $name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        if (! preg_match('/^[a-zA-Z0-9]+$/', $name)) {
            $this->error(__('Addon name incorrect'));
        }

        try {
            AddonService::uninstall($name, $force);
            $this->success(__('Uninstall successful'));
        } catch (Exception $e) {
            $this->error(__($e->getMessage()));
        }
    }

    /**
     * 禁用启用.
     */
    public function state()
    {
        $name = $this->request->post('name');
        $action = $this->request->post('action');
        $force = (int) $this->request->post('force');
        if (! $name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        if (! preg_match('/^[a-zA-Z0-9]+$/', $name)) {
            $this->error(__('Addon name incorrect'));
        }

        try {
            $action = $action == 'enable' ? $action : 'disable';
            //调用启用、禁用的方法
            AddonService::$action($name, $force);
            Cache::delete('__menu__');
            $this->success(__('Operate successful'));
        } catch (Exception $e) {
            $this->error(__($e->getMessage()));
        }
    }

    /**
     * 本地上传.
     */
    public function local()
    {
        Config::set(['default_return_type' => 'json'], 'app');
        $file = $this->request->file('file');
        $addonTmpDir = app()->getRootPath().'runtime'.DIRECTORY_SEPARATOR;
        if (! is_dir($addonTmpDir)) {
            @mkdir($addonTmpDir, 0755, true);
        }
        $validate = validate(['zip' => 'filesize:10240000|fileExt:zip'], [], false, false);
        if (! $validate->check(['zip' => $file])) {
            $this->error(__($validate->getError()));
        }
        $info = Filesystem::disk('runtime')->putFile('addons', $file, function ($file) {
            return str_replace('.'.$file->getOriginalExtension(), '', $file->getOriginalName());
        });
        if ($info) {
            $tmpName = str_replace('.'.$file->getOriginalExtension(), '', $file->getOriginalName());
            $tmpAddonDir = ADDON_PATH.$tmpName.DIRECTORY_SEPARATOR;
            $tmpFile = $addonTmpDir.$info;

            try {
                AddonService::unzip($tmpName);
                unset($info);
                @unlink($tmpFile);
                $infoFile = $tmpAddonDir.'info.ini';
                if (! is_file($infoFile)) {
                    throw new Exception(__('Addon info file was not found'));
                }

                //$config = Config::parse($infoFile, '', $tmpName);
                $config = parse_ini_file($infoFile, true, INI_SCANNER_TYPED) ?: [];
                $name = isset($config['name']) ? $config['name'] : '';
                if (! $name) {
                    throw new Exception(__('Addon info file data incorrect'));
                }
                if (! preg_match('/^[a-zA-Z0-9]+$/', $name)) {
                    throw new Exception(__('Addon name incorrect'));
                }

                $newAddonDir = ADDON_PATH.$name.DIRECTORY_SEPARATOR;

                if (is_dir($newAddonDir)) {
                    throw new Exception(__('Addon already exists'));
                }

                //重命名插件文件夹
                rename($tmpAddonDir, $newAddonDir);

                try {
                    //默认禁用该插件
                    $info = get_addon_info($name);
                    if ($info['state']) {
                        $info['state'] = 0;
                        set_addon_info($name, $info);
                    }

                    //执行插件的安装方法
                    $class = get_addon_class($name);
                    if (class_exists($class)) {
                        $addon = new $class();
                        $addon->install();
                    }

                    //导入SQL
                    AddonService::importsql($name);

                    $info['config'] = get_addon_config($name) ? 1 : 0;
                    $this->success(__('Offline installed tips'), null, ['addon' => $info]);
                } catch (Exception $e) {
                    @rmdirs($newAddonDir);

                    throw new Exception(__($e->getMessage()));
                }
            } catch (Exception $e) {
                unset($info);
                @unlink($tmpFile);
                @rmdirs($tmpAddonDir);
                $this->error(__($e->getMessage()));
            }
        } else {
            // 上传失败获取错误信息
            $this->error(__($file->getError()));
        }
    }

    /**
     * 更新插件.
     */
    public function upgrade()
    {
        $name = $this->request->post('name');
        $addonTmpDir = app()->getRuntimePath() . 'addons' . DS;
        if (! $name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        if (! preg_match('/^[a-zA-Z0-9]+$/', $name)) {
            $this->error(__('Addon name incorrect'));
        }
        if (!is_dir($addonTmpDir)) {
            @mkdir($addonTmpDir, 0755, true);
        }
        try {
            $uid = $this->request->post('uid');
            $token = $this->request->post('token');
            $version = $this->request->post('version');
            $faversion = $this->request->post('faversion');
            $extend = [
                'uid'       => $uid,
                'token'     => $token,
                'version'   => $version,
                'faversion' => $faversion,
            ];
            //调用更新的方法
            AddonService::upgrade($name, $extend);
            Cache::delete('__menu__');
            $this->success(__('Operate successful'));
        } catch (Exception $e) {
            $this->error(__($e->getMessage()));
        }
    }

    /**
     * 已装插件.
     */
    public function downloaded()
    {
        $offset = (int) $this->request->get('offset');
        $limit = (int) $this->request->get('limit');
        $filter = $this->request->get('filter');
        $search = $this->request->get('search');
        $search = htmlspecialchars(strip_tags($search));
        $onlineaddons = Cache::get('onlineaddons');
        if (! is_array($onlineaddons)) {
            $onlineaddons = [];
            $result = Http::sendRequest(config('fastadmin.api_url') . '/addon/index', [], 'GET', [
                CURLOPT_HTTPHEADER => ['Accept-Encoding:gzip'],
                CURLOPT_ENCODING   => "gzip"
            ]);
            if ($result['ret']) {
                $json = (array) json_decode($result['msg'], true);
                $rows = isset($json['rows']) ? $json['rows'] : [];
                foreach ($rows as $index => $row) {
                    $onlineaddons[$row['name']] = $row;
                }
            }
            Cache::set('onlineaddons', $onlineaddons, 600);
        }
        $filter = (array) json_decode($filter, true);
        $addons = get_addon_list();
        $list = [];
        foreach ($addons as $k => $v) {
            if ($search && stripos($v['name'], $search) === false && stripos($v['intro'], $search) === false) {
                continue;
            }

            if (isset($onlineaddons[$v['name']])) {
                $v = array_merge($v, $onlineaddons[$v['name']]);
            } else {
                $v['category_id'] = 0;
                $v['flag'] = '';
                $v['banner'] = '';
                $v['image'] = '';
                $v['donateimage'] = '';
                $v['demourl'] = '';
                $v['price'] = __('None');
                $v['screenshots'] = [];
                $v['releaselist'] = [];
            }
            $v['url'] = addon_url($v['name']);
            $v['url'] = str_replace($this->request->server('SCRIPT_NAME'), '', $v['url']);
            $v['createtime'] = filemtime(ADDON_PATH.$v['name']);
            if ($filter && isset($filter['category_id']) && is_numeric($filter['category_id']) && $filter['category_id'] != $v['category_id']) {
                continue;
            }
            $list[] = $v;
        }
        $total = count($list);
        if ($limit) {
            $list = array_slice($list, $offset, $limit);
        }
        $result = ['total' => $total, 'rows' => $list];

        $callback = $this->request->get('callback') ? 'jsonp' : 'json';

        return $callback($result);
    }
}
