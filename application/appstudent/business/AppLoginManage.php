<?php
namespace app\appstudent\business;
use app\student\model\Studentinfo;
use app\student\model\Organ;
use Login;
use think\Cache;
use think\Controller;
use think\Validate;
use app\index\business\UserLogin;
class AppLoginManage extends Controller
{
    protected  $logintype = 3;
    public function  __construct() {
        //定义空的数组对象
        $this->foo = (object)array();
        //定义空字符串
        $this->str = '';
        }
    /**
     * 学生端注册
     * @Author yr
     * @param $mobile
     * @param $password
     * @return array
     *
     */
    public function register($post){
        $rule = [
            'mobile'  => 'require|length:6,15',
            'prphone' => 'require',
            'code' => 'require',
            'key' => 'require',
        ];
        $msg = [
            'mobile.require' => lang('39001'),
            'mobile.length'     => lang('39002'),
            'prphone.require'  => lang('39004'),
            'code.require'        => lang('39005'),
            'key.require'        => lang('39006'),
        ];

        $loginobj = new UserLogin;
        $post = $loginobj->rsaDecode($post['data']);
        if(!verifyPassword($post['password'])){
            return return_format('',39000,lang('39000'));
        }
        $validate = new Validate($rule,$msg);
        $result   = $validate->check($post);
        if(true !== $result){
            return return_format('',39010,$validate->getError());
        }
        //判断验证码是否正确
        $cachedata = Cache::get('mobile'.$post['mobile']);
        if(empty( $cachedata)){
            return return_format('',39007,lang('39007'));
        }
        if(trim($cachedata) !== trim($post['code'])){
            //如果验证码输入错误超限 重新发送短信验证码
            if(!verifyErrorCodeNum($post['mobile'])){
                return return_format('',39008,lang('39008'));
            }
            return return_format('',39009,lang('39009'));
        }
        $studentmodel = new Studentinfo;
        $info = $studentmodel->checkLogin($post['mobile']);
        $studentmodel = new Studentinfo;
        if($info){
            return return_format('',39011,lang('39011'));
        }
        else{
            $encryptpass =$this->createUserMark($post['password']);
            $mix = $encryptpass['mix'];
            $password = $encryptpass['password'];
            //拼装插入信息
            $data = $post;
            $data['password'] = $password;
            $data['mix'] = $mix;
            $data['addtime'] = time();
            unset($data['key']);
            unset($data['code']);
            $registerid = $studentmodel->addStudent($data);
            if (empty($registerid)) {
                return return_format('', 39012, lang('39012'));
            }
            Cache::rm('mobile' . $post['mobile']);
            $result = $loginobj->internalLogin($this->logintype, $registerid, $post['key']);
            if ($result['code'] == 0) {
                return $result;
            } else {
                return return_format('', 39013, lang('39013'));
            }
        }
    }
    /**
     * 学生端登陆 登陆作废 统一调用jrc接口
     * @Author why
     * @param $mobile
     * @param $password
     * @return array
     *
     */
    public function login ($mobile,$password,$domain)
    {
        //先判断域名正确性
        if(!is_numeric($domain)){
            $isdomain = checkUrl($domain);
            if(!$isdomain){
                return return_format('',32004,'请输入正确的域名');
            }else{
                $domainArray = explode('.', $domain);
                $domainArray = explode('//', $domainArray[0]);
                $domain = $domainArray[1];
            }
        }
        $organobj = new Organ;
        $organinfo = $organobj->getOrganmsgByDomain($domain);
        if($organinfo['vip'] == 0) {
            return return_format('',32100,'请输入正确的机构id');
        }
        $organid = $organinfo['id'];
        //先判断手机号长度
        if(strlen($mobile)<6 || strlen($mobile)>12 || !is_numeric(rtrim($mobile))){
            return return_format($this->str,32000,'请输入6-12位手机号');
        }else{
            $studentmodel = new Studentinfo;
            $data = $studentmodel ->checkLogin($mobile,$organid);
        //如果长度没问题判断手机号是否存在,或者手机号被删除
        if(!$data || $data['delflag'] == 0){
            return return_format($this->str,32001,'手机号不存在');
        }else{
            //判断用户登录状态，是否禁用
        if($data['status'] == 1){
            return  return_format($this->str,32002,'该手机号已被禁用!请联系管理员');
        }
        $res = $this->checkUserMark($password,$data['mix'],$data['password']);
            //如果用户名存在判断密码是否正确
        if($res == false){
           return  return_format($this->str,32003,'密码错误');
        }else{
            //设置token
            unset($data['password']);
            unset($data['mix']);
            $loginobj = new Login;
            $token = $loginobj->settoken($data['id'],1);
            $data['token'] = $token;
            //机构图片
            $data['organimage'] = $organinfo['imageurl'];
            return  return_format($data,0,'登录成功');
        }

        }

    }

}
    /**
     * [checkUserMark description]
     * @Author wyx
     * @DateTime 2018-04-27T16:35:02+0800
     * @param    [string]                 $pass [用户提交密码]
     * @param    [string]                 $mix  [description]
     * @param    [type]                   $sign [description]
     * @return   [bool]                         [true 代表成功，false 代表失败]
     */
    private function checkUserMark($pass,$mix,$sign){
        $md5str = md5(md5($pass).$mix);

        for ($i=0; $i < 5; $i++) {
            $md5str = md5($md5str) ;
        }
        // var_dump($sign);
        // var_dump($md5str);exit();
        if($sign==$md5str){
            return true ;
        }else{
            return false ;
        }

    }
    /**
     * 给用户生成 密码 和 mix
     * [createUserMark 生成用户的机密字符存储在数据库，当用户登陆时比对]
     * 创建用户时调用
     * @Author wyx
     * @DateTime 2018-04-27T16:22:58+0800
     * @param    [string]            $pass    [密码]
     * @return   [type]                   [description]
     */
    public function createUserMark($pass){
        $mix = $this->getRandString(16) ;
        $md5str = md5(md5($pass).$mix);

        for ($i=0; $i < 5; $i++) {
            $md5str = md5($md5str) ;
        }

        return ['mix'=>$mix,'password'=>$md5str] ;

    }
    /**
     * [getRandString 生成随机字符串]
     * @Author wyx
     * @DateTime 2018-04-27T14:53:16+0800
     * @param    [int]                      [设置需要的字符串的长度默认为8]
     * @return   [string]                   [description]
     */
    public function getRandString($length=8){
        $numstr    = '1234567890' ;
        $originstr = 'abcdefghijklmnopqrstuvwxyz' ;
        $origin = str_repeat($numstr,6).$originstr.strtoupper($originstr) ;

        return substr(str_shuffle($origin), -$length);

    }
}
