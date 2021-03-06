<?php

require_once($_SERVER['DOCUMENT_ROOT'].'/classes/tservices/tservices_helper.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/tu/models/TServiceOrderModel.php');
require_once $_SERVER['DOCUMENT_ROOT'].'/classes/freelancer.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/classes/employer.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/classes/template.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/classes/smail.php';

require_once $_SERVER['DOCUMENT_ROOT'].'/classes/messages_tservices.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/classes/tservices/tservices_sms.php';

/**
 * ���������� �������� �����
 */
define('TSERVICES_TPL_MAIL_PATH', $_SERVER['DOCUMENT_ROOT'] . '/templates/mail/tu/');
define('TSERVICES_TPL_BASE_LAYOUT', 'layout.tpl.php');

define('TSERVICES_BINDS_TPL_MAIL_PATH', $_SERVER['DOCUMENT_ROOT'] . '/templates/mail/tservices_binds/');
define('TSERVICES_BINDS_TPL_BASE_LAYOUT', $_SERVER['DOCUMENT_ROOT'] . '/templates/mail/layout.tpl.php');


/**
 * Class tservices_smail
 * ����� ��� ������ � ��������� ����� ��� ��
 */
class tservices_smail extends smail
{
    
    protected $order = array();
    protected $is_emp;
    protected $debt_timestamp = NULL;

    
    protected $template_format = '%s_%s.tpl.php';
    protected $is_local = FALSE;

    
    protected $is_reserve = false;


    public function __construct() 
    {
        parent::__construct();

        $server = defined('SERVER')?strtolower(SERVER):'local';
        $this->is_local = ($server == 'local');
    }
    

    /**
     * �������� ����� ��������� ������� ����� ��� �� ������ ��������� 
     * � ����� ��������� ����������� �������� � ���� �� ������ �� ������� ��
     * 
     * @todo: ���� ������ ���������� ����������� �������� �� ����������� ;)
     * 
     * @param string $method
     * @param type $arguments
     * @return boolean
     */
    public function __call($method, $arguments) 
    {
        if($this->is_local) return FALSE;
        
        $method = '_' . $method;
        if(method_exists($this, $method)) 
        {
            call_user_func_array(array($this, $method), $arguments);
        }
        
        return TRUE;
    }

    

    /**
     * ������������� ��� ��������� ���������� ������
     * 
     * @param array $attributes
     * @return type
     */
    public function attributes($attributes = null) 
    {
        if (is_null($attributes)) {
            return get_object_vars($this);
        }

        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    

    public function sendEvent($message, $is_emp = TRUE, $status = NULL)
    {
        if(empty($this->order)) return FALSE;
        $prefix = ($is_emp)?'employer':'freelancer';
        
        //���������� ��
        messages_tservices::sendOrderStatus($this->order[$prefix]['login'], $message);
        //���������� ���
        tservices_sms::model($this->order[$prefix]['uid'])->sendOrderStatus(($status)?$status:$this->order['status'],$this->order['id']);
        
        return TRUE;
    }

    


    /**
     * ���������� ������ � ����������� �� ������� ������
     * 
     * @param type $status
     * @return boolean
     */
    public function _changeOrderStatus($status)
    {
        if(empty($this->order)) return FALSE;
        $ret = FALSE;
        
        $this->is_reserve = tservices_helper::isOrderReserve(
                $this->order['pay_type']
                );
        
        switch($status)
        {
            case TServiceOrderModel::STATUS_ACCEPT:
                $ret = $this->acceptOrder();
                break;
    
            case TServiceOrderModel::STATUS_CANCEL:
                $ret = $this->cancelOrder();
                break;
            
            case TServiceOrderModel::STATUS_DECLINE:
                $ret = $this->declineOrder();
                break;
            
            case TServiceOrderModel::STATUS_FRLCLOSE:
                $ret = $this->doneOrder();
                break;
            
            case TServiceOrderModel::STATUS_FIX:
                $ret = $this->fixOrder();
                break;
        }

        return $ret;
    }

    
    
    
    /**
     * ����������� ���������
     * � ���������� ������
     * 
     * @return boolean
     */
    public function doneOrder()
    {
        $result = $this->sendFromTemplate('done_order'.(($this->is_reserve)?'_reserve':''));
        $this->sendEvent($result['message'], true);
        $ret = $result['ret'];
        return $ret;
    }
    



    /**
     * ��������� ����������� ����������� 
     * � ������������� ���������� �����
     * 
     */
    public function fixOrder()
    {
       $result = $this->sendFromTemplate('fix_order');
       $this->sendEvent($result['message'], false);
       $ret = $result['ret'];
       return $ret;
    }

    



    /**
     * ���������� ������������ ������ �� ������ �� ������� �����������
     * 
     * @return boolean
     */
    public function declineOrder()
    {
        $this->recipient = $this->_formatFullname($this->order['employer'],true);
        $message = Template::render(
                TSERVICES_TPL_MAIL_PATH . 'decline_order_emp.tpl.php', 
                array(
                    'smail' => &$this, 
                    'order' => $this->order,
                    'params' => $this->_addUrlParams('e'),
                    'frl_fullname' => $this->_formatFullname($this->order['freelancer'])
                )
        );
        
        $this->message = nl2br($message);
        $ret = $this->send('text/html');
        
        //���.�������
        $this->sendEvent($message, TRUE);
        
        return $ret;       
    }

    /**
     * ���������� ���������� ������ �� ������ ������ ����������
     * 
     * @return boolean
     */
    public function cancelOrder()
    {
        $this->recipient = $this->_formatFullname($this->order['freelancer'],true);
        $message = Template::render(
                TSERVICES_TPL_MAIL_PATH . 'cancel_order_frl.tpl.php', 
                array(
                    'smail' => &$this, 
                    'order' => $this->order,
                    'params' => $this->_addUrlParams('f'),
                    'emp_fullname' => $this->_formatFullname($this->order['employer'])
                )
        );
        
        $this->message = nl2br($message);
        $ret = $this->send('text/html');
        
        //��������� ���.�������
        $this->sendEvent($message, FALSE);
        
        return $ret;
    }

    


    
    public function _closeOrderAndFeedback($status)
    {
        if(empty($this->order)) return FALSE;
        $ret = FALSE;
        
        switch($status)
        {
            case TServiceOrderModel::STATUS_ACCEPT:
                $result = $this->sendFromTemplate('close_order');
                //��� ������ �����?
                $status = $this->is_emp?TServiceOrderModel::STATUS_EMPCLOSE:TServiceOrderModel::STATUS_FRLCLOSE;
                //���.�������
                $this->sendEvent($result['message'], !$this->is_emp, $status);
                $ret = $result['ret'];
                break;
            case TServiceOrderModel::STATUS_FRLCLOSE:
                //TODO
                break;
            case TServiceOrderModel::STATUS_EMPCLOSE:
                $result = $this->sendFromTemplate('feedback_order');
                $ret = $result['ret'];
                break;
        }

        return $ret;
    }

    

    
    
    
    protected function sendFromTemplate($template)
    {
        $prefix = ($this->is_emp)?'emp':'frl';
        $sufix = ($this->is_emp)?'frl':'emp';
        $template = sprintf($this->template_format,$template, $sufix);
        
        $this->recipient = $this->_formatFullname($this->order[($this->is_emp)?'freelancer':'employer'],true);
        $content = Template::render(
                TSERVICES_TPL_MAIL_PATH . $template, 
                array(
                    'smail' => &$this,
                    'order' => $this->order,
                    'params' => $this->_addUrlParams($this->is_emp?'f':'e'),
                    $prefix . '_fullname' => $this->_formatFullname($this->order[($this->is_emp)?'employer':'freelancer'])
                )
        );       

        $message = Template::render(
                TSERVICES_TPL_MAIL_PATH . TSERVICES_TPL_BASE_LAYOUT, 
                array(
                    'content' => $content
                )
        ); 
        
        $this->message = nl2br($message);
        
        return array('ret' => $this->send('text/html'), 'message' => $message); 
    }

    





    /*
    public function closeOrder()
    {
        $prefix = ($this->is_emp)?'emp':'frl';
        $sufix = ($this->is_emp)?'frl':'emp';

        $this->recipient = $this->_formatFullname($this->order[($this->is_emp)?'freelancer':'employer'],true);
        $this->message = Template::render(
                TSERVICES_TPL_MAIL_PATH . "close_by_{$prefix}_for_{$sufix}.tpl.php", 
                array(
                    'smail' => &$this, 
                    'order' => $this->order,
                    'params' => $this->_addUrlParams($this->is_emp?'f':'e'),
                    $prefix . '_fullname' => $this->_formatFullname($this->order[($this->is_emp)?'employer':'freelancer'])
                )
        );
        
        $ret = $this->send('text/html');        
        
        return $ret;
    }
    */
    




   


    public function acceptOrder()
    {
        $is_reserve = tservices_helper::isOrderReserve($this->order['pay_type']);
        
        //���������� ����������
        //@todo: ���� ������ ��� ������� ����� � ��� ������� ������ ������ �� ����������
        //@todo: ���������� �� �������� � ���������� ���� ��� ������������� ����� ����!
        $ret_f = true; 
        if($is_reserve) 
        {
            $this->recipient = $this->_formatFullname($this->order['freelancer'],true);
            $message = Template::render(
                TSERVICES_TPL_MAIL_PATH . 'accept_order_reserve_frl.tpl.php', 
                array(
                    'smail' => &$this, 
                    'order' => $this->order,
                    'params' => $this->_addUrlParams('f'),
                    'emp_fullname' => $this->_formatFullname($this->order['employer'])
                )
            );  
            
            $this->message = nl2br($message);
            $ret_f = $this->send('text/html');
        }
        
        /*  
        $this->recipient = $this->_formatFullname($this->order['freelancer'],true);
        $this->message = Template::render(
                TSERVICES_TPL_MAIL_PATH . 'accept_order_'.($this->debt_timestamp?'debt_':'').'frl.tpl.php', 
                array(
                    'smail' => &$this, 
                    'order' => $this->order,
                    'params' => $this->_addUrlParams('f'),
                    'emp_fullname' => $this->_formatFullname($this->order['employer']),
                    'debt_timestamp' => $this->debt_timestamp
                )
        );
        
        $ret_f = $this->send('text/html');
        */
        
        
        //���������� ���������
        $this->recipient = $this->_formatFullname($this->order['employer'],true);
        
        $template = ($is_reserve)?'accept_order_reserve_emp.tpl.php':'accept_order_emp.tpl.php';
        $status = ($is_reserve)?tservices_sms::STATUS_RESERVE_ACCEPT:NULL;
        $message = Template::render(
                TSERVICES_TPL_MAIL_PATH . $template,
                array(
                    'smail' => &$this, 
                    'order' => $this->order,
                    'params' => $this->_addUrlParams('e'),
                    'frl_fullname' => $this->_formatFullname($this->order['freelancer'])
                )
        );
        
        $this->message = nl2br($message);
        $ret_e = $this->send('text/html');
        
        //���.�������
        $this->sendEvent($message, TRUE, $status);
        
        return ($ret_f && $ret_e);
    }

    

    /**
     * ��������� ����� �����
     * @todo �� ������ ����� ��� �����?
     * 
     * @param type $user
     * @param type $with_email
     * @return type
     */
    protected function _formatFullname(&$user, $with_email = false)
    {
        $u = (is_object($user))?array(
            'uname' => $user->uname,
            'usurname' => $user->usurname,
            'login' => $user->login,
            'email' => $user->email
            ):$user;
        
        $fullname = "{$u['uname']}";
        $fullname .= ((empty($fullname))?"":" ") . "{$u['usurname']}";
        $fullname .= (empty($fullname))?"{$u['login']}":" [{$u['login']}]";
        if($with_email) $fullname .= " <{$u['email']}>";
        return $fullname;
    }

    


    /**
     * ���������� ������ ����������� � ������ �� ���� �� ��������� � �����������.
     * 
     * @param array $order
     * @return boolean
     */
    public function _newOrder($order)
    {
        if(empty($order)) return FALSE;

        $employer = new employer();
        $employer->GetUserByUID(@$order['emp_id']);
        if(!$employer->uid) return FALSE;

        $freelancer = new freelancer();
        $freelancer->GetUserByUID(@$order['frl_id']);
        if(!$freelancer->uid) return FALSE;
        
        //���������� ���������
        $this->recipient = $this->_formatFullname(&$employer,true);
        $template = ($order['pay_type'] == 1)?'new_order_reserve_emp.tpl.php':'new_order_emp.tpl.php';
        $this->message = Template::render(
                TSERVICES_TPL_MAIL_PATH . $template, 
                array(
                    'smail' => &$this, 
                    'order' => $order,
                    'params' => $this->_addUrlParams('e'),
                    'frl_fullname' => $this->_formatFullname(&$freelancer)
                )
        );

        $ret_e = $this->send('text/html');
        
        //���������� ����������
        $this->recipient = $this->_formatFullname(&$freelancer,true);
        $template = ($order['pay_type'] == 1)?'new_order_reserve_frl.tpl.php':'new_order_frl.tpl.php';
        $message = Template::render(
                TSERVICES_TPL_MAIL_PATH . $template, 
                array(
                    'smail' => &$this, 
                    'order' => $order,
                    'params' => $this->_addUrlParams('f'),
                    'emp_fullname' => $this->_formatFullname(&$employer)
                )
        );
        $this->message = nl2br($message);
        $ret_f = $this->send('text/html');
        
        //��������� � ��
        messages_tservices::sendOrderStatus($freelancer->login,$message);
        //��������� ���
        $status = ($order['pay_type'] == 1)?tservices_sms::STATUS_NEW_RESERVE:TServiceOrderModel::STATUS_NEW;
        tservices_sms::model($freelancer->uid)->sendOrderStatus($status, $order['id']);
        
        return ($ret_e && $ret_f);
    }

    
    
    
    /**
     * ������ ����������� ���������� � ���������
     * 
     * @param type $new_order
     * @param type $old_order
     * @return boolean
     */
    public function _changeOrder2($new_order, $old_order)
    {
        if(empty($new_order) || empty($old_order)) return false;
        
        //��������� ��������� ������� �����������
        $params = array(
            'order_price',
            'order_days',
            'pay_type'
        );
        
        foreach($params as $value)
        {
            if ($old_order[$value] != $new_order[$value]) 
            {
                $new_order["old_{$value}"] = $old_order[$value];
            }
        }
        
        $this->order = $new_order;
        $this->is_emp = true;
        //������ ����������
        $res_frl = $this->sendFromTemplate('change2_order');
        $this->is_emp = false;
        //������ ���������
        $res_emp = $this->sendFromTemplate('change2_order');
        
        //���������� ��� ����������
        tservices_sms::model($this->order['freelancer']['uid'])->sendOrderStatus(tservices_sms::STATUS_CHANGE_ORDER, $this->order['id']);
        
        return ($res_frl['ret'] && $res_emp['ret']);
    }

    



    /**
     * �� ������������!
     * ���������� ������ ����������� ����������� �� ��������� ������ �� ���� ��.
     * 
     * @param array $order
     * @return boolean
     */
    public function _changeOrder($order) 
    {
        if(empty($order)) return FALSE;
        
        //@todo: ��� ������������� �������� ������������� � ������ ��� ����!
        $employer = new employer();
        $employer->GetUserByUID(@$order['emp_id']);
        if(!$employer->uid) return FALSE;

        $freelancer = new freelancer();
        $freelancer->GetUserByUID(@$order['frl_id']);
        if(!$freelancer->uid) return FALSE;
        
        $this->recipient = $this->_formatFullname(&$freelancer,true);
        $message = Template::render(
                TSERVICES_TPL_MAIL_PATH . 'change_order_frl.tpl.php', 
                array(
                    'smail' => &$this, 
                    'order' => $order,
                    'params' => $this->_addUrlParams('f'),
                    'emp_fullname' => $this->_formatFullname(&$employer)
                )
        );
        $this->message = nl2br($message);
        $ret_f = $this->send('text/html');
        
        return ($ret_f);
    }
    
    
    /**
     * �������� ����������� � ����������� ������� ����� ����� �������� ������
     * �� 24 � 72 ���� ���������������.
     * 
     * @return int
     */
    public function noneFeedbackOrders()
    {
        $host = $GLOBALS['host'];
        
        $message = Template::render(
                TSERVICES_TPL_MAIL_PATH . TSERVICES_TPL_BASE_LAYOUT, 
                array(
                    'host' => $host,
                    'params' => '',//'%PARAMS%',
                    'content' => '%CONTENT%'
                )
        );     
        $this->message = nl2br($message);
        
        $count = 0;
        $subjects = array(
            24 => "�� �� �������� ����� � �������������� �� ������",
            //72 => "�� ��� ��� �� �������� ����� � �������������� �� ������"
            144 => "�� ��� ��� �� �������� ����� � �������������� �� ������"
        );
        
        $model = TServiceOrderModel::model();
        
        foreach($subjects as $hours => $subject)
        {
            $page  = 0;
            $data = $model->getNoneFeedbackOrders($hours, ++$page, 200);
            if(!$data) continue;        
            
            $this->subject = $subject;
            $this->recipient = '';
            $massId = $this->send('text/html'); 
        
            do
            {
                foreach($data as $el)
                {

                    $content = Template::render(
                        TSERVICES_TPL_MAIL_PATH . "none_feedback_order_{$hours}h.tpl.php", 
                        array(
                            'order' => $el
                        )
                    );
                        
                    
                    //��� ������ � ���������� ��� �� ��������
                    if(!$el['frl_feedback_id'] && @$el['allow_fb_frl'] == 't')
                    {
                        $freelancer = array(
                            'uname' => $el['frl_uname'],
                            'usurname' => $el['frl_usurname'],
                            'login' => $el['frl_login'],
                            'email' => $el['frl_email']
                        );                   

                        $this->recipient[] = array(
                            'email' => $this->_formatFullname($freelancer,true),
                            'extra' => array(
                                //'PARAMS' => $this->_addUrlParams('f'),
                                'CONTENT' => $content
                            )
                        ); 
                    
                        $count++;
                    }
               
                    //��� ������ � ��������� ��� �� ��������
                    if(!$el['emp_feedback_id'] && @$el['allow_fb_emp'] == 't')
                    {
                        $employer = array(
                            'uname' => $el['emp_uname'],
                            'usurname' => $el['emp_usurname'],
                            'login' => $el['emp_login'],
                            'email' => $el['emp_email']                   
                        );                
               
                        $this->recipient[] = array(
                            'email' => $this->_formatFullname($employer,true),
                            'extra' => array(
                                //'PARAMS' => $this->_addUrlParams('e'),
                                'CONTENT' => $content
                            )
                        ); 
                    
                        $count++;
                    }
                }
            
                $this->bind($massId, true);
            }
            while( $data = $model->getNoneFeedbackOrders($hours, ++$page, 200) );
        }
        
        return $count;      
    }

    



    /**
     * �������� ����������� �����������
     * �� �������� �� ������ �� 24 � 72 ���� ��������������.
     * 
     * @return int
     */
    public function inactiveOrders()
    {
        $host = $GLOBALS['host'];
        
        $message = Template::render(
                TSERVICES_TPL_MAIL_PATH . TSERVICES_TPL_BASE_LAYOUT, 
                array(
                    'host' => $host,
                    'params' => '',//$this->_addUrlParams('f'),
                    'content' => '%CONTENT%'
                )
        );        
        $this->message = nl2br($message);
        
        $count = 0; 
        $subjects = array(
            24 => "� ��� �� ����������� �����",
            72 => "� ��� ��� ��� �� ����������� �����"
        );
        
        $model = TServiceOrderModel::model();
        foreach($subjects as $hours => $subject)
        {
            $page  = 0;
            $data = $model->getInactiveOrders($hours, ++$page, 200);
            if(!$data) continue;
            
            $this->subject = $subject;
            $this->recipient = '';
            $massId = $this->send('text/html'); 
            
            do
            {
                foreach($data as $el)
                {
                    $freelancer = array(
                        'uname' => $el['frl_uname'],
                        'usurname' => $el['frl_usurname'],
                        'login' => $el['frl_login'],
                        'email' => $el['frl_email']
                    ); 
               
                    $employer = array(
                        'uname' => $el['emp_uname'],
                        'usurname' => $el['emp_usurname'],
                        'login' => $el['emp_login'],
                        'email' => $el['emp_email']                   
                    );
               
                    $content = Template::render(
                        TSERVICES_TPL_MAIL_PATH . "inactive_order_{$hours}h_frl.tpl.php", 
                        array( 
                            'order' => $el,
                            'emp_fullname' => $this->_formatFullname($employer)
                        )
                    );

                    $this->recipient[] = array(
                        'email' => $this->_formatFullname($freelancer,true),
                        'extra' => array(
                            //'USER_NAME'         => $freelancer['uname'],
                            //'USER_SURNAME'      => $freelancer['usurname'],
                            //'USER_LOGIN'        => $freelancer['login'],
                            'CONTENT'             => $content
                        )
                    );                
                
                    $count++;
                }
            
                $this->bind($massId, true);
                
            }
            while( $data = $model->getInactiveOrders($hours, ++$page, 200) );
        }

        
        return $count; 
    }
    
    
    /**
     * ����������� ���������� ����� ����, 
     * ��� ������ ���������� �� 4 ����� � ���� � ������ ����������� ��
     * 
     * @return type
     */
    public function remindBindsUp() 
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . "/classes/tservices/tservices_binds.php";
        require_once $_SERVER['DOCUMENT_ROOT'] . "/classes/quick_payment/quickPaymentPopupTservicebind.php";
        require_once $_SERVER['DOCUMENT_ROOT'] . "/classes/quick_payment/quickPaymentPopupTservicebindup.php";
        
        
        $tservices_categories = new tservices_categories();
            
        //������� ������ ������
        $layout = Template::render(
                TSERVICES_BINDS_TPL_BASE_LAYOUT, 
                array(
                    'params' => '',//$this->_addUrlParams('f'),
                    'content' => '%CONTENT%'
                )
        );
        
        $this->message = nl2br($layout);

        $count = 0; 
        $page  = 0;
        while ($binds = tservices_binds::getDowned(++$page, 50)) {
            
            $bind_ids = array();
            foreach ($binds as $el) {
                
                $kind_txt = '';
                $link = '';
                $link_prolong = '/?' . quickPaymentPopupTservicebind::getPopupId($el['tservice_id']) . '=1';
                $link_up = '/?' . quickPaymentPopupTservicebindup::getPopupId($el['tservice_id']) . '=1';
                
                switch ($el['kind']) {
                    case tservices_binds::KIND_LANDING:
                        $kind_txt = '�� ������� �������� �����';
                        break;
                    
                    case tservices_binds::KIND_ROOT:
                        $kind_txt = '� ����� ������� �������� �����';
                        $link = '/tu';
                        break;
                    
                    case tservices_binds::KIND_GROUP:
                        $category = $tservices_categories->getCategoryById($el['prof_id']);
                        $kind_txt = sprintf("� ������� %s �������� �����", @$category['title']);
                        $link = sprintf("/tu/%s", @$category['link']);
                        break;
                    
                    case tservices_binds::KIND_SPEC:
                        $category = $tservices_categories->getCategoryById($el['prof_id']);
                        $kind_txt = sprintf("� ���������� %s �������� �����", @$category['title']);
                        $link = sprintf("/tu/%s", @$category['link']);
                        break;
                }
                
                $link_prolong = $link . $link_prolong;
                $link_up = $link . $link_up;
                
                //������ �����������
                $content = Template::render(
                    TSERVICES_BINDS_TPL_MAIL_PATH . "remind_up.tpl.php", 
                    array(
                        'smail' => $this,
                        'kind' => $kind_txt,
                        'title' => $el['title'],
                        'link_up' => $link_up,
                        'link_prolong' => $link_prolong
                    )
                );

                $this->recipient[] = array(
                    'email' => $this->_formatFullname($el,true),
                    'extra' => array(
                        'CONTENT' => nl2br($content)
                    )
                ); 

                $bind_ids[] = $el['id'];
            }
            
            $count += count($bind_ids);
            
            $massId = $this->send('text/html');
            
            if ($massId) {
                tservices_binds::markSent('up', $bind_ids);
            }            

        }
        
        return $count;
    }
    
    
    
    
    /**
     * ����������� ���������� �� 1 ���� �� ��������� 
     * ���������� ����������� ��
     */
    public function remind24hEndBinds() 
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . "/classes/tservices/tservices_binds.php";
        require_once $_SERVER['DOCUMENT_ROOT'] . "/classes/quick_payment/quickPaymentPopupTservicebind.php";
        
        $tservices_categories = new tservices_categories();
            
        //������� ������ ������
        $layout = Template::render(
                TSERVICES_BINDS_TPL_BASE_LAYOUT, 
                array(
                    'params' => '',//$this->_addUrlParams('f'),
                    'content' => '%CONTENT%'
                )
        );
        
        $this->message = nl2br($layout);

        $count = 0; 
        $page  = 0;
        
        while ($binds = tservices_binds::getExpiring(++$page, 200)) {
            
            $bind_ids = array();
            foreach ($binds as $el) {
                
                $kind_txt = '';
                $link = '/?' . quickPaymentPopupTservicebind::getPopupId($el['tservice_id']) . '=1';
                
                switch ($el['kind']) {
                    case tservices_binds::KIND_LANDING:
                        $kind_txt = '�� ������� �������� �����';
                        break;
                    
                    case tservices_binds::KIND_ROOT:
                        $kind_txt = '� ����� ������� �������� �����';
                        $link = '/tu' . $link;
                        break;
                    
                    case tservices_binds::KIND_GROUP:
                        $category = $tservices_categories->getCategoryById($el['prof_id']);
                        $kind_txt = sprintf("� ������� %s �������� �����", @$category['title']);
                        $link = sprintf("/tu/%s%s", @$category['link'], $link);
                        break;
                    
                    case tservices_binds::KIND_SPEC:
                        $category = $tservices_categories->getCategoryById($el['prof_id']);
                        $kind_txt = sprintf("� ���������� %s �������� �����", @$category['title']);
                        $link = sprintf("/tu/%s%s", @$category['link'], $link);
                        break;
                }
                
                //������ �����������
                $content = Template::render(
                    TSERVICES_BINDS_TPL_MAIL_PATH . "remind_prolong.tpl.php", 
                    array(
                        'smail' => $this,
                        'time' => dateFormat('H:i', $el['date_stop']),
                        'kind' => $kind_txt,
                        'title' => $el['title'],
                        'link' => $link
                    )
                );

                $this->recipient[] = array(
                    'email' => $this->_formatFullname($el,true),
                    'extra' => array(
                        'CONTENT' => nl2br($content)
                    )
                ); 

                $bind_ids[] = $el['id'];
            }
            
            $count += count($bind_ids);
            
            $massId = $this->send('text/html');
            
            if ($massId) {
                tservices_binds::markSent('prolong', $bind_ids);
            }
        }
    
        return $count;
    }
    
    
    
}