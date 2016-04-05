<?php

// 特殊帖子处理类

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

require_once "config.inc.php";
require_once "library/PTHelper.php";

/**
 * 特殊帖子-资源帖
 */
class threadplugin_ngpt
{
    /**
     * 特殊帖子类型名称。
     * @var string $name
     */
    public $name = '发布资源';
    /**
     * 特殊帖子的图标
     * @var string $iconfile
     */
    public $iconfile = '';
    /**
     * 发布特殊帖子的按钮显示的文字
     * @var string $buttontext
     */
    public $buttontext = '发布资源';

    function postThreadToSeedServer($fid, $tid = null)
    {
        global $_G;
        $hasfile = true;
        if (empty($_FILES['torrent']) || !file_exists($_FILES['torrent']['tmp_name'])) {
            $hasfile = false;
            if ($tid === null) {
                showmessage("请上传种子文件");
            }
        }
        $seed_id = 0;
        if ($tid !== null) {
            $seed = DB::fetch_first("SELECT seed_id FROM ".DB::table("ngpt_seed").
                " WHERE tid=$tid");
            if (empty($seed)) {
                throw new Exception("Seed being edited not exists");
            } else {
                $seed_id = $seed['seed_id'];
            }
        }
        //把整个表单传给seed服务器进行验证。
        if (empty($_POST['typeid'])) {
            //为了进行下面的搜索，设置一个不存在的值
            $_POST['typeid'] = 99999999;
        }
        $field = $_POST['typeid'];
        $type_id = array_search($field, $this->thread_types);
        $tmp = [
            'pub_form' => json_encode($_POST),
            'type_id' => $fid,
            'sub_type_id' => $type_id,
            'meta_info_id' => $fid,
        ];
        if ($tid !== null) {
            $tmp['seed_id'] = $seed_id;
        }
        if ($hasfile) {
            $tmp['torrentFile'] = new CURLFile(
                $_FILES['torrent']['tmp_name'],
                "application/x-bittorrent",
                $_FILES['torrent']['name']
            );
        }
        $res = PTHelper::getApiCurl("seed/upload", $tmp, true);

        if (empty($res) ||
            empty($res['result']) ||
            $res['result'] == 'failed') {
            $msg = '';
            foreach ($res['extra'] as $k => $v) {
                $subm = '';
                if (!is_array($v)) {
                    $v = [$v];
                }
                foreach ($v as $submsg) {
                    $subm .= $submsg . "</br>";
                }
                $msg .= '</br>' . $subm . '</br>';
            }
            showmessage("发表失败，原因：" . $msg);
        }

        switch ($res['result']) {
            case 'exists':
                $t_qresult = DB::fetch_first("SELECT tid FROM " .
                    DB::table('ngpt_seed') .
                    " WHERE seed_id='{$res['extra']}'");
                showmessage(
                    '种子已存在，即将跳转。',
                    $_G['siteroot'] .
                    "forum.php?mod=viewthread&tid={$t_qresult['tid']}"
                );
                return false;
            case 'succeed':
                $_G['uploaded_seed'] = $res['extra'];
                return true;
            case 'invalid':
                showmessage(
                    '种子曾经被删除，禁止再次发布，即将跳转。'
                );
                return false;
            default:
                //不会出现这种情况！
                throw new Exception("Unknown response from seed server");
        }
    }

    function getThreadTypes()
    {
        global $_G;
        $return_prepare_threadtypes = 'var _my_threadtypes = [];';
// 获取版块类别索引（如果有）
// 这样，在发布资源帖的JS代码里就可以用 _my_threadtypes[0..n] 来访问当前 typeid 控件所对应的值了。
// 例如，在动漫版，_my_threadtypes[0] = 1；在音乐版，_my_threadtypes[0] = 9。但是二者都对应于逻辑上的本版块的帖子分类索引。
// 这样，如果要修改版块的帖子分类，只需要修改 _my_threadtypes 访问的索引值即可，不用关心这个值到底是多少——这解决了原来硬编码选择索引导致要更新所有版块的选择值的问题。
// 例子见 newthread-60.htm。
        if ($_G['forum']['threadtypes']['types']) {
            $_x_counter = 0;
            $_types_v = array_keys($_G['forum']['threadtypes']['types']);
            foreach ($_types_v as $_types_v_v) {
                $return_prepare_threadtypes .= "_my_threadtypes[{$_x_counter}] = {$_types_v_v};" . PHP_EOL;
                $_x_counter++;
            }
        }
        return $return_prepare_threadtypes;
    }
    /**
     * 处理新建资源帖逻辑，并返回一个 Discuz 模板，该模板会被加入到编辑框前
     * 例:
     * return template("common/header");
     * @param int $fid 版块ID
     * @return string
     */
    function newthread($fid)
    {
        /**
         * @var array $_G
         */
        global $_G;
        $form_json = PTHelper::getApiCurl("seed/get-form-json", ['fid' => $fid]);
        $types_js = $this->getThreadTypes();
        if ($form_json['result'] == 'succeed') {
            return <<<HTML
<script>
{$types_js}
var formjson = {$form_json['json']};
</script>
<div class="exfm cl">
    <div class="sinf sppoll z" id="post_seed_form">
    </div>
</div>
<script src="./source/plugin/ngpt/static/js/post-seed.js"></script>
HTML;
        } else {
            showmessage("种子上传表单获取失败，请联系管理员解决");
            return null;
        }
    }

    public $thread_types;

    /**
     * 在新帖子提交前审核要提交的数据（通过 $_POST[]），可以否决
     * @param int $fid 版块ID
     */
    public function __construct()
    {
        global $_G;
        $_threadtypes = $_G['forum']['threadtypes']['types'];
        $_my_threadtypes = array_keys($_threadtypes);
        $this->thread_types = $_my_threadtypes;
    }

    function newthread_submit($fid)
    {
        return $this->postThreadToSeedServer($fid);
    }

    /**
     * 在新帖子提交后处理提交的数据（通过 $_POST[]）
     * @param int $fid 版块ID
     * @return bool 是否上传成功
     * @throws Exception
     */
    function newthread_submit_end($fid, $tid)
    {
        global $_G;
        if (empty($_G['uploaded_seed'])) {
            throw new Exception("seed server 传回的信息不完全");
        }
        $tbl = DB::table("ngpt_seed");
        $sql = <<<SQL
INSERT INTO {$tbl}(seed_id, tid, fid, publisheruid)
VALUES(
'{$_G['uploaded_seed']['seed_id']}',
'{$tid}',
'{$fid}',
'{$_G['uid']}'
);
SQL;
        $ret = DB::query($sql);
        if ($ret !== false) {
            return true;
        } else {
            throw new Exception("记录种子信息失败");
        }
    }

    /**
     * 处理编辑资源帖逻辑，并返回一个 Discuz 模板，该模板会被加入到编辑框前
     * 例:
     * return template("common/header");
     * @param int $fid 论坛ID
     * @param int $tid 正在编辑的帖子ID
     * @return string
     */
    function editpost($fid, $tid)
    {
        global $_G;
        /**
         * @var string $return
         * @var string $return_prepare
         */
        $seed = PTHelper::getSeedInfoBy('tid', $tid);
        $detail_info = json_decode($seed['detail_info'], true);
        $type_id = $seed['sub_type_id'];
        $fields = array_values($detail_info);
        $form_json = PTHelper::getApiCurl("seed/get-form-json", ['fid' => $fid]);
        $types_js = $this->getThreadTypes();

        // JavaScript 中的 fields 从0开始
        $return_prepare_fields = 'var fields = [];';
        foreach ($fields as $fieldkey => $fieldvalue){
            $fieldvalue = addslashes($fieldvalue);
            $return_prepare_fields .= "fields[{$fieldkey}] = '{$fieldvalue}';";
        }

        return <<<HTML
<script>
var editmode = true;
var formjson = {$form_json['json']};
{$return_prepare_fields}
{$types_js}
</script>
<div class="exfm cl">
    <div class="sinf sppoll z" id="post_seed_form">
    </div>
</div>
<script src="./source/plugin/ngpt/static/js/post-seed.js"></script>
HTML;
    }

    /**
     * 在新帖子完成编辑前审核要提交的数据（通过 $_POST[]），可以否决
     * @param int $fid 版块ID
     * @param int $tid 帖子ID
     */
    function editpost_submit($fid, $tid)
    {
        return $this->postThreadToSeedServer($fid, $tid);
    }

    /**
     * 在新帖子完成编辑后处理提交的数据（通过 $_POST[]）
     * @param int $fid 版块ID
     * @param int $tid 帖子ID
     */
    function editpost_submit_end($fid, $tid)
    {
        //啥都不用干
    }

    /**
     * 意义不明，似乎是发表新回复后处理新回复的提交数据（通过 $_POST[]）
     * @param int $fid 版块ID
     * @param int $tid 帖子ID
     */
    function newreply_submit_end($fid, $tid)
    {

    }

    /**
     * 处理浏览帖子逻辑，并返回一个 Discuz 模板，该模板会被加入到帖子内容前
     * 例:
     * return template("common/header");
     * @param int $tid 即将浏览的帖子ID
     * @return string
     */
    function viewthread($tid)
    {
        $record_seed = PTHelper::getSeedInfoBy('tid', $tid);
        $seedexists = $record_seed['is_valid'];
        $seedid = $record_seed['seed_id'];
        $infohash = $record_seed['info_hash'];
        $filename = $record_seed['file_name'];
        $filesize = $record_seed['file_size'];
        $filesize_str = PTHelper::getReadableFileSize($filesize);
        $filecount = $record_seed['file_count'];
        $seeds = $record_seed['seeder_count'];
        $leechers = $record_seed['leecher_count'];
        $finished = $record_seed['completed_count'];
        $lastactive = strtotime($record_seed['last_active_time']);
        $lastactive_str = date('Y-m-d H:i:s', $lastactive);
        $status = 'Normal'; //废弃的
        $pubdate = strtotime($record_seed['pub_time']);
        $pubdate_str = date('Y-m-d H:i:s', $lastactive);
        $traffic = $record_seed['traffic_up'];
        $traffic_str = PTHelper::getReadableFileSize($traffic);
        $livetime = $record_seed['live_time'];
        $livetime_str = PTHelper::getReadableTimeFromMinutes($livetime / 60.0);

        $coef_expire = intval($record_seed['coef_expire_time']);
        if ($coef_expire != 0) {
            $coef_expire = PTHelper::getReadableTimeFromMinutes($coef_expire / 60.0);
        } else {
            $coef_expire = "永久";
        }
        $upcoeff = $record_seed['up_coef'];
        $upcoeff_str = $upcoeff . "%   " . $coef_expire;
        $downcoeff = $record_seed['down_coef'];
        $downcoeff_str = $downcoeff . "%   " . $coef_expire;
        $seedingip_str = 'ipv4/ipv6';

        $filename = $record_seed['torrent_name'];
        $downseedpageurl = PTHelper::getApiUrl(
            "seed/download",
            [
                'seed_id' => $record_seed['seed_id'],
            ]
        );

        $downseedpageurl = preg_replace(
            "/127.0.0.1/",
            $_SERVER['SERVER_ADDR'],
            $downseedpageurl
        );

        $candownloadstatus  = 'normal';
        $return = null;
        include template('ngpt:resthread-view');
        return $return;
    }
}

?>
