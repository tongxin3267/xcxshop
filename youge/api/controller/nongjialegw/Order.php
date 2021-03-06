<?php

namespace app\api\controller\nongjialegw;

use app\api\controller\Common;
use app\common\model\nongjiale\CommentModel;
use app\common\model\nongjiale\CommentphotoModel;
use app\common\model\nongjiale\PackageModel;
use app\common\model\nongjiale\RoomModel;
use app\common\model\nongjiale\RoompriceModel;
use app\common\model\nongjiale\TaocanModel;
use app\common\model\nongjiale\TaocanpriceModel;
use app\common\model\setting\SettingcouponModel;
use app\common\model\nongjiale\OrderModel;
use app\common\model\user\CouponModel;
use app\common\model\miniapp\MiniappsettingModel;
use app\common\library\MiniApp;
class Order extends Common {

    protected $checklogin = true;
    
    
    public function cancel(){
        $id = (int) $this->request->param('id');
        if(empty($id)){
            $this->result([], '400', '不存在该订单', 'json');
        }
        $OrderModel= new OrderModel();
        if (!$order = $OrderModel->find($id)) {
            $this->result([], '400', '不存在该订单', 'json');
        }
        if ($order->user_id != $this->user->user_id) {
            $this->result([], '400', '不存在该订单', 'json');
        }
        if ($order->status != 0) {
            $this->result([], '400', '订单状态不可取消', 'json');
        }
        $cancel_info = $this->request->param('cancel_info');
        if(empty($cancel_info)){
             $this->result([], '400', '取消理由不能为空', 'json');
        }
       if ($OrderModel->save(['status'=>3,'cancel_type'=>1,'cancel_time'=>  $this->request->time(),'cancel_info'=>$cancel_info],['order_id'=>$id])){
           if(!empty($order->pay_coupon_id)){
               $CouponModel = new CouponModel();
               $CouponModel->save(['is_can'=>0],['coupon_id'=>$order->pay_coupon_id]);
           } 

             //发送模版消息
           $formId = $this->request->param('formId');
           $Miniapp = new MiniApp($this->appid);
           $Miniapp->sendTemplateMessage('AT0012', $this->user->open_id, $formId,'/pages/hotel/order/detail?id='.$id,[
               $id,
               date('Y-m-d H:i:s',$order->add_time),
               $cancel_info,
               '未支付订单，无需退款',
           ]);
           $TaocanpackagepriceModel = new TaocanpriceModel();
           $TaocanpackagepriceModel->where(['day'=>$order->bg_time,'package_id'=>$order->package_id])->setDec('day_num',$order->product_num);
           $this->result([], '200', '取消成功', 'json');
       }
       $this->result([], '400', '订单状态不可取消', 'json');
    }
    
    public function getPayment(){
        $id = (int) $this->request->param('order_id');
        if(empty($id)){
            $this->result([], '400', '不存在该订单', 'json');
        }
        $OrderModel= new OrderModel();
        if (!$order = $OrderModel->find($id)) {
            $this->result([], '400', '不存在该订单', 'json');
        }
        if ($order->user_id != $this->user->user_id) {
            $this->result([], '400', '不存在该订单', 'json');
        }
        if ($order->status != 0) {
            $this->result([], '400', '订单状态不可支付', 'json');
        }
        $SettingModel = new MiniappsettingModel();
        $setting = $SettingModel->get($this->appid);
        define('WX_APPID',$setting['mini_appid']);
        define('WX_MCHID',$setting['mini_mid']);
        define('WX_KEY',$setting['mini_apicode'] );
        define('WX_APPSECRET',$setting['mini_appsecrept']);
        define('WX_SSLCERT_PATH',ROOT_PATH.'/attachs/wxapiclient/'.$setting['apiclient_cert']);
        define('WX_SSLKEY_PATH',ROOT_PATH.'/attachs/wxapiclient/'.$setting['apiclient_cert_key']);
        define('WX_CURL_PROXY_HOST','0.0.0.0');
        define('WX_CURL_PROXY_PORT',0);
        define('WX_REPORT_LEVENL',0);
        require_once ROOT_PATH."/weixinpay/lib/WxPay.Api.php";
        require_once ROOT_PATH."/weixinpay/example/WxPay.JsApiPay.php";
        $tools = new \JsApiPay();
        $input = new \WxPayUnifiedOrder();
        $input->SetBody("套餐预定".$id);
        $input->SetAttach($id);
        $input->SetOut_trade_no(WX_MCHID.rand(1000,9999).$id);
        $input->SetTotal_fee($order->need_pay);
        $input->SetTime_start(date("YmdHis"));
        $input->SetTime_expire(date("YmdHis", time() + 600));
        $input->SetNotify_url("https://".$_SERVER['HTTP_HOST']."/api/weixin/nongjiale/appid/".$this->appid.'.html');
        $input->SetTrade_type("JSAPI");
        $input->SetOpenid($this->request->param('openid'));
        $order = \WxPayApi::unifiedOrder($input);
        $jsApiParameters = $tools->GetJsApiParameters($order);
        $this->result(['order'=>  json_decode($jsApiParameters,true)], '200', '创建支付成功！', 'json');
    }

    public function create() {
        $day = date('Y-m-d', strtotime($this->request->param('day')));
        $today = date('Y-m-d', $this->request->time());
        if ($day < $today ) {
            $this->result([], '400', '日期不正确', 'json');
        }
        $num = (int) $this->request->param('num');
        $name = $this->request->param('name');
        $mobile = $this->request->param('mobile');
        $idcard = $this->request->param('idcard');
        $person_num = (int)  $this->request->param('person_num');
        if (empty($name) || empty($mobile) || empty($idcard)) {
            $this->result([], '400', '联系人、手机号码、身份证不能为空', 'json');
        }

        $id = (int) $this->request->param('id');
        if (empty($id)) {
            $this->result([], '400', '没有该套餐1', 'json');
        }
        $package = PackageModel::get($id);
        if (empty($package)) {
            $this->result([], '400', '没有该套餐2', 'json');
        }
        if($package->member_miniapp_id != $this->appid){
            $this->result([], '400', '没有该套餐3', 'json');
        }
        $TaocanModel = new TaocanModel();
        if(!$taocan = $TaocanModel->get($package->taocan_id)){
            $this->result([], '400', '该套餐不存在了', 'json');
        }

        $TaocanpackagepriceModel= new TaocanpriceModel();
        $prices = $TaocanpackagepriceModel->backPrice($package->taocan_id,$this->appid,$day);
        if(empty($prices[$id])){
            $this->result([],'400','没有该套餐4','json');
        }

        $yudingnum = $maxnum = 0; //已经被预定的最大数量
        $pricesIds = $pricesNum = [];
        $maxnum = $prices[$id]['surplus_num']; //最大可以下单的间数
        if ($num <= 0 || $num > $maxnum) {
            $this->result([], '400', '该日期内房型比较紧张', 'json');
        }
        $totalprice = $num * $prices[$id]['price'];
        $lijian = 0;
        $setting = MiniappsettingModel::get($this->appid);
        if (!empty($setting->is_pay)) { //开启了支付立减的
            $lijian =$setting->pay_money;
        }
        $lijiannum = (int) ($totalprice / 10000);//立减只支持预定一间房子
        $lijian = $lijian * $lijiannum;

        //最终的总价格
        $totalprice = $totalprice*$num;
        $hongbao = 0;
        $hongbaoId = (int) $this->request->param('hongbaoId');
        if (!empty($hongbaoId)) {
            $coupon = CouponModel::get($hongbaoId);
            if ($coupon->user_id != $this->user->user_id) {
                $this->result([], '400', '红包不可用', 'json');
            }
            if ($coupon->type != 1) {
                $this->result([], '400', '红包不可用', 'json');
            }
            if (($coupon->expir_time < $this->request->time()) || ($coupon->can_use_time > $this->request->time() ) || ($coupon->is_can != 0) || ($coupon->need_money > $totalprice)) {
                $this->result([], '400', '红包不可用', 'json');
            }
            $hongbao = $coupon->money;
        }
        
        $needpay = $totalprice - $lijian - $hongbao;
        $needpay = $needpay <=0 ? 0 : $needpay;

        $order = [
            'member_miniapp_id' => $this->appid,
            'user_id'           => $this->user->user_id,
            'package_id'           => $id,
            'product_id'          =>$package->taocan_id,
            'order_type'         => 1,
            'product_num'        =>$num,
            'total_price'       => $totalprice,
            'need_pay'          => $needpay,
            'pay_coupon'        => $hongbao,
            'lijian'            => $lijian,
            'pay_coupon_id'     => $hongbaoId,
            'name'  => $name,
            'mobile'    => $mobile,
            'idcard'    => $idcard,
            'bg_time' => $day,
            'status' => $needpay==0 ? 1: 0, //如果是0元了代表优惠券已经抵扣了！
        ];
       // die;
        $OrderModel = new OrderModel();
       if($OrderModel->save($order)){
           if(!empty($coupon)){
              $CouponModel = new CouponModel();
              $CouponModel->save(['is_can'=>1],['coupon_id'=>$hongbaoId]);
           }
           
           //发送模版消息
           $formId = $this->request->param('formId');
           $Miniapp = new MiniApp($this->appid);
           $Miniapp->sendTemplateMessage('AT0008', $this->user->open_id, $formId,'/pages/hotel/order/detail?id='.$OrderModel->order_id,[
               $OrderModel->order_id,
               round($needpay/100,2),
               '等待支付',
               date('Y-m-d H:i:s'),
               '请在15分中内完成支付，否则订单将会取消'
           ]);

              if(empty($prices[$id]['price_id'])){
                    $TaocanpackagepriceModel->save([
                        'package_id'  => $id,
                        'taocan_id'   => $package->taocan_id,
                        'member_miniapp_id' => $this->appid,
                        'day'   =>  $day,
                        'day_num' => $maxnum - $num,
                        'price'  => $prices[$id]['price'],
                    ]);
              }else{
                  $TaocanpackagepriceModel->save(['day_num'=> $maxnum - $num ],['price_id'=>$prices[$id]['price_id']]);
              }
           $TaocanModel->where(['taocan_id'=>$package->taocan_id])->setInc('order_num');
            $this->result(['id'=>$OrderModel->order_id], '200', '创建订单成功', 'json');
       }
       $this->result([], '400', '创建订单失败', 'json');
    }

    //进入购买页面先从服务器拉取一下数据
    public function checkOrder() {
        //这个是房屋的ID

        $day = date('Y-m-d', strtotime($this->request->param('day')));
        $today = date('Y-m-d', $this->request->time());
        if ($day < $today) {
            $this->result([], '400', '日期不正确', 'json');
        }
        $id = (int) $this->request->param('id');
        if (empty($id)) {
            $this->result([], '400', '没有该套餐1', 'json');
        }
        $package = PackageModel::get($id);
        if (empty($package)) {
            $this->result([], '400', '没有该套餐2', 'json');
        }
        if($package->member_miniapp_id != $this->appid){
            $this->result([], '400', '没有该套餐3', 'json');
        }

        $TaocanpackagepriceModel= new TaocanpriceModel();
        $price = $TaocanpackagepriceModel->backPrice($package->taocan_id,$this->appid,$day);
        if(empty($price[$id])){
            $this->result([],'400','没有该套餐','json');
        }


        $maxnum = $price[$id]['surplus_num']; //最大可以下单的间数
        $totalprice = $price[$id]['price'];
        $lijian = 0;
        $setting = MiniappsettingModel::get($this->appid);
        if (!empty($setting->is_pay)) { //开启了支付立减的
            $lijian = round($setting->pay_money / 100, 2);
        }
        $return = [
            'lijian' => (int) ($lijian), //立减只减1间的钱，不减第二间的
            'totalprice' => round($totalprice / 100, 2),
            'room' => [
                'id' => $package->package_id,
                'title' => $package->title,
                'photo' => IMG_URL . getImg($package->photo),
                'price' => round($package->price / 100, 2),
            ],
            'maxnum' => $maxnum,
        ];
        $this->result($return, '200', '加载成功', 'json');
    }
    /*
     * 获取用户订单列表
     * type 0 全部 1 有效单 2待支付 3退款单 4 带评论；
     */

    public function geTorder() {
        $type = (int) $this->request->param('type');
        $where['is_delete'] = 0;
        $where['user_id'] = $this->user->user_id;
        switch ($type) {
            case 1:
                $where['status'] = [['=', 2], ['=', 8], 'or'];
                break;
            case 2:
                $where['status'] = 0;
                break;
            case 3:
                $where['status'] = 3;
                break;
            case 4:
                $where['status'] = 8;
                $where['is_comment'] = 0;
                break;
        }
        $OrderModel = new OrderModel();
        $totalNum = $OrderModel->where($where)->count();
        $list = $OrderModel->where($where)->order("order_id desc")->limit($this->limit_bg, $this->limit_num)->select();
        if (empty($list)) {
            $data['totalNum'] = $totalNum;
            $data['list'] = [];
            $this->result($data, '200', '没有数据了', 'json');
        }
        $roomIds = $taocanIds = $packageIds = [0];
        foreach ($list as $val) {
            if($val->order_type == 1){
            $taocanIds[$val->product_id] = $val->product_id;
            }elseif($val->order_type == 2){
                $roomIds[$val->product_id] = $val->product_id;
            }
            $packageIds[$val->package_id] = $val->package_id;
        }
        $PackageModel = new PackageModel();
        $packages = $PackageModel->itemsByIds($packageIds);
        $RoomModel = new RoomModel();
        $rooms = $RoomModel->itemsByIds($roomIds);
        $TaocanModel = new TaocanModel();
        $taocans = $TaocanModel->itemsByIds($taocanIds);
        $data['totalNum'] = $totalNum;
        $data['list'] = [];
        foreach ($list as $val) {
            $data['list'][] = [
                'order_id' => $val->order_id,
                'user_id' => $val->user_id,
                'order_type' => $val->order_type,
                'taocan_name' => empty($taocans[$val->product_id]) ? '' : $taocans[$val->product_id]->title,
                'package_name' => empty($packages[$val->package_id]) ? '' : $packages[$val->package_id]->title,
                'room_name'  => empty($rooms[$val->product_id]) ? '' : $rooms[$val->product_id]->title,
                'total_price' => sprintf("%.2f", $val->total_price / 100),
                'idcard' => $val-> idcard,
                'bg_time' =>  $val->bg_time,
                'end_time' =>  $val->end_time,
                'product_num' => $val->product_num,
                'name' => $val->name,
                'mobile' => $val->mobile,
                'status' => $val->status,
                'status_mean' => empty(config('dataattr.orderstatus')[$val->status]) ? '' : config('dataattr.orderstatus')[$val->status],
            ];
        }
        $data['more']  = count($data['list']) == $this->limit_num ? 1: 0;
        $this->result($data, '200', '数据初始化成功', 'json');
    }
    /**
     * 订单详情；
     * */
    public function orderDetail2() {
        $order_id = (int) $this->request->param('order_id');
        $OrderModel = new OrderModel();
        if(!$order = $OrderModel->find($order_id)){
            $this->result([],'400','不存在订单','json');
        }
        if($order->member_miniapp_id != $this->appid){
            $this->result([],'400','不存在订单','json');
        }
        $TaocanModel = new TaocanModel();
        $taocan = $TaocanModel->find($order->product_id);
        $PackageModel = new PackageModel();
        $package = $PackageModel->find($order->package_id);
        $data = [
            'order_id' => $order->order_id,
            'product_id' => $order->product_id,
            'package_id' => $order->package_id,
            'package_name' => empty($package) ? '' : $package->title,
            'taocan_name' => empty($taocan) ? '' : $taocan->title,
            'package_num' => $order->product_num,
            'total_price' => sprintf("%.2f",$order->total_price/100),
            'need_pay' => sprintf("%.2f",$order->need_pay/100) ,
            'pay_money' => sprintf("%.2f", $order->pay_money/100),
            'pay_coupon' => $order->pay_coupon,
            'lijian' =>  sprintf("%.2f",$order->lijian/100),
            'pay_coupon_id' => $order->pay_coupon_id,
            'pay_time' => $order->pay_time,
            'name' => $order->name,
            'mobile' => $order->mobile,
            'idcard' => $order->idcard,
            'status' => $order->status,
            'bg_time' => $order->bg_time,
            'cancel_time' => $order->cancel_time,
            'cancel_type' => $order->cancel_type,
            'cancel_info' => $order->cancel_info,
            'is_comment' => $order->is_comment,
            'cancel_type_mean' => $order->cancel_type == 1 ? '客户取消' : '商家拒接',
            'add_time'  => date("Y-m-d H:i:s",$order->add_time),
            'status_mean' => empty(config('dataattr.orderstatus')[$order->status]) ? '' : config('dataattr.orderstatus')[$order->status],
        ];
        $this->result($data,'200','数据初始化成功','json');
    }

    public function orderDetail() {
        $order_id = (int) $this->request->param('order_id');
        if(empty($order_id)){
            $this->result([], '400', '不存在该订单', 'json');
        }
        $HotelorderModel = new OrderModel();
        if (!$order = $HotelorderModel->find($order_id)) {
            $this->result([], '400', '不存在该订单', 'json');
        }
        if ($order->user_id != $this->user->user_id) {
            $this->result([], '400', '不存在该订单', 'json');
        }
        if ($order->member_miniapp_id != $this->appid) {
            $this->result([], '400', '不存在该订单', 'json');
        }
        $room = RoomModel::find($order->product_id);
        $config = config('dataattr.orderstatus');
        $bedtype = config('dataattr.hotelbedtype');
        $data = [
            'order_id' => $order->order_id,
            'product_id' => $order->product_id,
            'total_price' => sprintf("%.2f", $order->total_price / 100),
            'need_pay' => sprintf("%.2f", ($order->need_pay) / 100),
            'lijian' => sprintf("%.2f", ($order->lijian) / 100),
            'pay_coupon' => sprintf("%.2f", ($order->pay_coupon) / 100),
            'address' => empty($hotel) ? '' : $hotel->address,
            'lat' => empty($hotel) ? '' : $hotel->lat,
            'lng' => empty($hotel) ? '' : $hotel->lng,
            'room_title' => empty($room) ? '' : $room->title,
            'room_num' => $order->product_num,
            'bg_time' => $order->bg_time,
            'end_time' => $order->end_time,
            'day' => (int)((strtotime($order->end_time) - strtotime($order->bg_time))/86400),
            'is_wifi' => $room->is_wifi,
            'name' => $order->name,
            'mobile' => $order->mobile,
            'idcard' => $order->idcard,
            'tel' => empty($hotel) ? '' : $hotel->hotel_tel,
            'add_time' => date("Y-m-d H:i:s", $order->add_time),
            'status' => $order->status,
            'is_comment' => $order->is_comment,
            'status_mean' => empty($config[$order->status]) ? '' : $config[$order->status],
        ];
        $this->result($data, 200, '数据初始化成功', 'json');
    }

    /*
     * @param score = 评分；
     * commnert = 内容；
     * img 图片
     */


    public function create1() {
        $bg_date = date('Y-m-d', strtotime($this->request->param('bg_date')));
        $end_date = date('Y-m-d', strtotime($this->request->param('end_date')));
        $today = date('Y-m-d', $this->request->time());
        if ($bg_date < $today || $end_date < $bg_date) {
            $this->result([], '400', '日期不正确', 'json');
        }
        $num = (int) $this->request->param('num');
        $name = $this->request->param('name');
        $mobile = $this->request->param('mobile');
        $idcard = $this->request->param('idcard');
        $person_num = (int)  $this->request->param('person_num');
        if (empty($name) || empty($mobile) || empty($idcard)) {
            $this->result([], '400', '联系人、手机号码、身份证不能为空', 'json');
        }


        $id = (int) $this->request->param('id');
        if (empty($id)) {
            $this->result([], '400', '没有该房型', 'json');
        }
        $room = RoomModel::get($id);
        if (empty($room)) {
            $this->result([], '400', '没有该房型', 'json');
        }
        if($room->member_miniapp_id != $this->appid){
            $this->result([], '400', '没有该房型', 'json');
        }

        $prices = RoompriceModel::where(['room_id' => $id, 'day' => ['BETWEEN', [$bg_date, $end_date]]])->select();

        $pricesData = [];
        $yudingnum = $maxnum = 0; //已经被预定的最大数量
        $pricesIds = $pricesNum = [];
        foreach ($prices as $val) {
            $pricesData[$val->day] = $val->price;
            $pricesIds[$val->day] = $val->price_id;
            $pricesNum[$val->day] = $val->room_num;
            $yudingnum = $yudingnum > $val->room_num ? $yudingnum : $val->room_num;
        }
        $maxnum = $room->day_num - $yudingnum; //最大可以下单的间数
        if ($num <= 0 || $num > $maxnum) {
            $this->result([], '400', '该日期内房型比较紧张', 'json');
        }

        $bg_time = strtotime($bg_date);
        $end_time = strtotime($end_date);
        $priceList = [];
        $totalprice = 0;
        for ($i = $bg_time; $i < $end_time; $i = $i + 86400) {
            $day = date('Y-m-d', $i);
            $price = isset($pricesData[$day]) ? $pricesData[$day] : $room->price;
            $totalprice+= $price;
        }


        $lijian = 0;
        $setting = MiniappsettingModel::get($this->appid);
        if (!empty($setting->is_pay)) { //开启了支付立减的
            $lijian =$setting->pay_money;
        }
        $lijiannum = (int) ($totalprice / 10000);//立减只支持预定一间房子
        $lijian = $lijian * $lijiannum;
        //最终的总价格
        $totalprice = $totalprice*$num;
        $hongbao = 0;
        $hongbaoId = (int) $this->request->param('hongbaoId');
        if (!empty($hongbaoId)) {
            $coupon = CouponModel::get($hongbaoId);
            if ($coupon->user_id != $this->user->user_id) {
                $this->result([], '400', '红包不可用', 'json');
            }
            if ($coupon->type != 1) {
                $this->result([], '400', '红包不可用', 'json');
            }
            if (($coupon->expir_time < $this->request->time()) || ($coupon->can_use_time > $this->request->time() ) || ($coupon->is_can != 0) || ($coupon->need_money > $totalprice)) {
                $this->result([], '400', '红包不可用', 'json');
            }
            $hongbao = $coupon->money;
        }

        $needpay = $totalprice - $lijian - $hongbao;
        $needpay = $needpay <=0 ? 0 : $needpay;

        $order = [
            'member_miniapp_id' => $this->appid,
            'user_id'           => $this->user->user_id,
            'product_id'           => $id,
            'product_num'          => $num,
            'order_type'         => 2,
            'total_price'       => $totalprice,
            'need_pay'          => $needpay,
            'pay_coupon'        => $hongbao,
            'lijian'            => $lijian,
            'pay_coupon_id'     => $hongbaoId,
            'name'  => $name,
            'mobile'    => $mobile,
            'idcard'    => $idcard,
            'bg_time' => $bg_date,
            'end_time'  => $end_date,
            'status' => $needpay==0 ? 1: 0, //如果是0元了代表优惠券已经抵扣了！
        ];
        // die;
        $HotelOrderModel = new OrderModel();
        if($HotelOrderModel->save($order)){
            if(!empty($coupon)){
                $CouponModel = new CouponModel();
                $CouponModel->save(['is_can'=>1],['coupon_id'=>$hongbaoId]);
            }

            //发送模版消息
            $formId = $this->request->param('formId');
            $Miniapp = new MiniApp($this->appid);
            $Miniapp->sendTemplateMessage('AT0008', $this->user->open_id, $formId,'/pages/hotel/order/detail?id='.$HotelOrderModel->order_id,[
                $HotelOrderModel->order_id,
                round($needpay/100,2),
                '等待支付',
                date('Y-m-d H:i:s'),
                '请在15分中内完成支付，否则订单将会取消'
            ]);
            //冻结库存
            $saveAll = [];
            for ($i = $bg_time; $i < $end_time; $i = $i + 86400) {
                $day = date('Y-m-d', $i);
                if(isset($pricesIds[$day])){
                    $data = [
                        'price_id' => $pricesIds[$day],
                        'room_id' => $room->room_id,
                        'member_miniapp_id' => $room->member_miniapp_id,
                        'day' => $day,
                        'price' => isset($pricesData[$day]) ? $pricesData[$day]:$room->price,
                        'room_num' => $pricesNum[$day] + $num,
                    ];
                }else{
                    $data = [
                        'room_id' => $room->room_id,
                        'member_miniapp_id' => $room->member_miniapp_id,
                        'day' => $day,
                        'price' => $room->price,
                        'room_num' =>  $num,
                    ];
                }
                $saveAll[] = $data;
            }
            $priceModel = new RoompriceModel();
            $priceModel->saveAll($saveAll);


            $this->result(['id'=>$HotelOrderModel->order_id], '200', '创建订单成功', 'json');
        }
        $this->result([], '400', '创建订单失败', 'json');
    }

    //进入购买页面先从服务器拉取一下数据
    public function checkOrder1() {
        //这个是房屋的ID
        $bg_date = date('Y-m-d', strtotime($this->request->param('bg_date')));
        $end_date = date('Y-m-d', strtotime($this->request->param('end_date')));
        $today = date('Y-m-d', $this->request->time());
        if ($bg_date < $today || $end_date < $bg_date) {
            $this->result([], '400', '日期不正确', 'json');
        }
        $id = (int) $this->request->param('id');
        if (empty($id)) {
            $this->result([], '400', '没有该房型1', 'json');
        }
        $room = RoomModel::get($id);
        if (empty($room)) {
            $this->result([], '400', '没有该房型2', 'json');
        }
        if($room->member_miniapp_id != $this->appid){
            $this->result([], '400', '没有该房型3', 'json');
        }

        $prices = RoompriceModel::where(['room_id' => $id, 'day' => ['BETWEEN', [$bg_date, $end_date]]])->select();
        ;
        $pricesData = [];
        $yudingnum = $maxnum = 0; //已经被预定的最大数量
        foreach ($prices as $val) {
            $pricesData[$val->day] = $val->price;
            $yudingnum = $yudingnum > $val->room_num ? $yudingnum : $val->room_num;
        }
        $maxnum = $room->day_num - $yudingnum; //最大可以下单的间数
        $bg_time = strtotime($bg_date);
        $end_time = strtotime($end_date);
        $priceList = [];
        $totalprice = 0;
        for ($i = $bg_time; $i < $end_time; $i = $i + 86400) {
            $day = date('Y-m-d', $i);
            $price = isset($pricesData[$day]) ? $pricesData[$day] : $room->price;
            $totalprice+= $price;
            $priceList[] = [
                'day' => $day,
                'price' => round($price / 100, 2),
            ];
        }
        $lijian = 0;
        $setting = MiniappsettingModel::get($this->appid);
        if (!empty($setting->is_pay)) { //开启了支付立减的
            $lijian = round($setting->pay_money / 100, 2);
        }
        $config = config('dataattr.hotelbedtype');
        $roomtype = config('dataattr.hotelroomtypenames');
        $num = (int) ($totalprice / 10000);
        $return = [
            'lijian' => (int) ($lijian * $num), //立减只减1间的钱，不减第二间的
            'priceList' => $priceList,
            'totalprice' => round($totalprice / 100, 2),
            'room' => [
                'id' => $room->room_id,
                'title' => $room->title,
                'area' => $room->area,
                'photo' => IMG_URL . getImg($room->photo),
                'appropriate_num' => $room->appropriate_num,
                'is_wifi' => $room->is_wifi,
                'price' => round($room->price / 100, 2),
            ],
            'maxnum' => $maxnum,
        ];
        $this->result($return, '200', '加载成功', 'json');
    }

    //去取消订单：房间；
    public function cancel1(){
        $id = (int) $this->request->param('id');
        if(empty($id)){
            $this->result([], '400', '不存在该订单', 'json');
        }
        $HotelorderModel = new OrderModel();
        if (!$order = $HotelorderModel->find($id)) {
            $this->result([], '400', '不存在该订单', 'json');
        }
        if ($order->user_id != $this->user->user_id) {
            $this->result([], '400', '不存在该订单', 'json');
        }
        if ($order->status != 0) {
            $this->result([], '400', '订单状态不可取消', 'json');
        }
        $cancel_info = $this->request->param('cancel_info');
        if(empty($cancel_info)){
            $this->result([], '400', '取消理由不能为空', 'json');
        }
        if ($HotelorderModel->save(['status'=>3,'cancel_type'=>1,'cancel_time'=>  $this->request->time(),'cancel_info'=>$cancel_info],['order_id'=>$id])){
            if(!empty($order->pay_coupon_id)){
                $CouponModel = new CouponModel();
                $CouponModel->save(['is_can'=>0],['coupon_id'=>$order->pay_coupon_id]);
            }
            //发送模版消息
            $formId = $this->request->param('formId');
            $Miniapp = new MiniApp($this->appid);
            $Miniapp->sendTemplateMessage('AT0012', $this->user->open_id, $formId,'/pages/hotel/order/detail?id='.$id,[
                $id,
                date('Y-m-d H:i:s',$order->add_time),
                $cancel_info,
                '未支付订单，无需退款',
            ]);

            $HotelpriceModel = new RoompriceModel();
            $HotelpriceModel->removeBooked($order->product_id,$order->bg_time,$order->end_time,$order->product_num);
            $this->result([], '200', '取消成功', 'json');
        }
        $this->result([], '400', '订单状态不可取消', 'json');
    }

    public function comment() {
        $order_id = (int) $this->request->param('order_id');
        $HotelorderModel = new OrderModel();
        if (!$order = $HotelorderModel->find($order_id)) {
            $this->result([], '400', '不存在该订单', 'json');
        }
        if ($order->user_id != $this->user->user_id) {
            $this->result([], '400', '不存在该订单', 'json');
        }
        if ($order->status != 8) {
            $this->result([], '400', '订单未完成', 'json');
        } else if ($order->is_comment == 1) {
            $this->result([], '400', '该订单以评论', 'json');
        }
        $data['score'] = ((float) $this->request->param('score')) * 10;
        if ($data['score'] <= 0 || $data['score'] > 50) {
            $this->result([], 400, '请评分', 'json');
        }
        $data['content'] = $this->request->param('content');
        if (empty($data['content'])) {
            $this->result([], 400, '请评论内容', 'json');
        }
        $CommentModel = new CommentModel();
        $CommentModel->save([
            'member_miniapp_id' => $this->appid,
            'product_id' => $order->product_id,
            'user_id' => $order->user_id,
            'type' => $order->order_type,
            'order_id' => $order->order_id,
            'score' => $data['score'],
            'content' => $data['content'],
        ]);

        $_img  = file_get_contents("php://input");
        $_img =  json_decode($_img,true);
        $img = $_img['photo'];
        if(!empty($img)){
            $photo = [];
            foreach ($img as $val) {
                $photo[] = [
                    'comment_id' => $CommentModel->comment_id,
                    'member_miniapp_id' => $this->appid,
                    'photo' => $val,
                ];
            }
            $CommentphotoModel = new CommentphotoModel();
            $CommentphotoModel->saveAll($photo);
        }
        $HotelModel = new TaocanModel();
        switch ($data['score']) {
            case $data['score'] < 25;
                $HotelModel->where('taocan_id', $order->product_id)->setInc('bad_num');
                break;
            case $data['score'] > 35;
                $HotelModel->where('taocan_id', $order->product_id)->setInc('praise_num');
                break;
        }
        $HotelorderModel->save(['is_comment' => 1], ['order_id' => $order_id]);
        $ordernum = $HotelorderModel->where(['user_id' => $this->user->user_id, 'member_miniapp_id' => $this->appid])->count();
        //首单赠送红包；
        $coupon_data = [];
        if ($ordernum <= 1) {
            $settngCouponModel = new SettingcouponModel();
            $coupondetail = $settngCouponModel->find($this->appid);
            $coupon = unserialize($coupondetail->value)['order'];
            if ($coupon['money'] > 0) {
                $CouponModel = new CouponModel();
                $coupon_data = [
                    'user_id' => $this->user->user_id,
                    'member_miniapp_id' => $this->appid,
                    'way' => 3,
                    'need_money' => $coupon['need_money'],
                    'money' => $coupon['money'],
                    'expir_time' => $coupon['expire_day'] <= 0 ? 7 * 86400 + time() : $coupon['expire_day'] * 86400 + time(),
                    'can_use_time' => $coupon['use_day'] <= 0 ? $this->request->time() : $coupon['use_day'] * 86400 + time(),
                ];
                $CouponModel->save($coupon_data);
            }
        }
        $this->result($coupon_data, 200, '评论成功', 'json');
    }
}
