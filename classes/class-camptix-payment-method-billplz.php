<?php
/**
 * CampTix Billplz Payment Method
 *
 * This class handles all Billplz integration for CampTix
 *
 * @since		1.0
 * @package		CampTix
 * @category	Class
 */
if (!defined('ABSPATH'))
    exit; // Exit if accessed directly

class CampTix_Payment_Method_Billplz extends CampTix_Payment_Method
{

    public $id = 'camptix_billplz';
    public $name = 'Billplz';
    public $description = 'CampTix payment methods for Billplz.';
    public $supported_currencies = array('MYR');

    const QUERY_VAR = 'billplz_camptix_call';
    const LISTENER_PASSPHRASE = 'camptix_billplz_listener_passphrase';

    /**
     * We can have an array to store our options.
     * Use $this->get_payment_options() to retrieve them.
     */
    protected $options = array();

    /**
     * This is to Initiate the CampTix options
     */
    function camptix_init()
    {
        $this->options = array_merge(array(
            'api_key' => '',
            'x_signature' => '',
            'collection_id' => '',
            'notification' => false
            ), $this->get_payment_options());

        // IPN Listener
        add_action('template_redirect', array($this, 'template_redirect'));
    }

    /**
     * CampTix fields in the settings section for entering the Payment Credentials
     */
    function payment_settings_fields()
    {
        $this->add_settings_field_helper('api_key', 'API Secret Key', array($this, 'field_text'), __("Get your API Secret Key at www.billplz.com.", 'billplz'));
        $this->add_settings_field_helper('x_signature', 'X Signature', array($this, 'field_text'), __("Get your X Signature Key at www.billplz.com.", 'billplz'));
        $this->add_settings_field_helper('collection_id', __('Collection ID', 'billplz'), array($this, 'field_text'), __("This field is Optional. Leave blank if you unsure.", 'billplz'));
        $this->add_settings_field_helper('notification', __('Send Bills', 'billplz'), array($this, 'field_yesno'), __("We recommend you to turn off this feature. If Yes, charge RM0.15 per bills sent", 'billplz')
        );
    }

    /**
     * CampTix validate the submited options
     */
    function validate_options($input)
    {
        $output = $this->options;

        if (isset($input['api_key']))
            $output['api_key'] = $input['api_key'];
        if (isset($input['x_signature']))
            $output['x_signature'] = $input['x_signature'];
        if (isset($input['collection_id']))
            $output['collection_id'] = $input['collection_id'];
        if (isset($input['notification']))
            $output['notification'] = (bool) $input['notification'];

        return $output;
    }

    /**
     * Handle the API Redirect as per the GET value submitted in the CampTix Process
     */
    function template_redirect()
    {
        if (!isset($_GET[self::QUERY_VAR]))
            return;
        $passphrase = get_option(self::LISTENER_PASSPHRASE, false);
        if (!$passphrase) {
            return;
        }
        if ($_GET[self::QUERY_VAR] != $passphrase) {
            return;
        }

        if (isset($_GET['billplz']['id'])) {
            $data = Billplz::getRedirectData($this->options['x_signature']);
        } else {
            $data = Billplz::getCallbackData($this->options['x_signature']);
            sleep(10);
        }
        $billplz = new Billplz($this->options['api_key']);
        $moreData = $billplz->check_bill($data['id']);
        $paid_time = $billplz->get_bill_paid_time($data['id']);
        $this->process_payment($moreData, $paid_time);
    }

    function process_payment($moreData, $paid_time)
    {
        $this->log(sprintf('Running payment_return. Request data attached.'), null, $_REQUEST);
        $this->log(sprintf('Running payment_return. Server data attached.'), null, $_SERVER);

        $payment_token = get_option('billplz_camptix_' . $moreData['id'], false);

        if (!$payment_token)
            return;

        $attendees = get_posts(
            array(
                'posts_per_page' => 1,
                'post_type' => 'tix_attendee',
                'post_status' => array('draft', 'pending', 'publish', 'cancel', 'refund', 'failed'),
                'meta_query' => array(
                    array(
                        'key' => 'tix_payment_token',
                        'compare' => '=',
                        'value' => $payment_token,
                        'type' => 'CHAR',
                    ),
                ),
            )
        );

        if (empty($attendees))
            return;

        $attendee = reset($attendees);

        $access_token = get_post_meta($attendee->ID, 'tix_access_token', true);
        $payment_status = get_post_meta($attendee->ID, 'tix_transaction_id', true);

        if ($moreData['paid']) {
            if (empty($payment_status)) {
                $strlog = 'SUCCESS. Bill ID=' . $moreData['id'] . ' | URL=' . $moreData['url'] . ' | State=' . $moreData['state'] . ' | Name=' . $moreData['name'];
                $strlog .= ' | Time=' . gmdate('d-m-Y H:i:s', $paid_time);
                $this->log($strlog);
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, ['transaction_id' => $moreData['id'], 'transaction_details' => $strlog]);
            }
        } else {
            $strlog = 'FAILED. Bill ID=' . $moreData['id'] . ' | URL=' . $moreData['url'] . ' | State=' . $moreData['state'] . ' | Name=' . $moreData['name'];
            $this->log($strlog);
            $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, ['transaction_id' => $moreData['id'], 'transaction_details' => $strlog]);
        }

        if (isset($_GET['billplz']['id'])) {
            if ($moreData['paid']) {
                global $camptix;
                $url = add_query_arg(array(
                    'tix_action' => 'access_tickets',
                    'tix_access_token' => $access_token,
                    ), $camptix->get_tickets_url());
            } else {
                $url = add_query_arg(array(
                    'tix_action' => 'payment_cancel',
                    'tix_payment_token' => $payment_token,
                    'tix_payment_method' => 'camptix_kdcpay',
                    ), $camptix->get_tickets_url());
            }
            wp_safe_redirect(esc_url_raw($url . '#tix'));
            die();
        }
        exit('OK');
    }

    public static function get_listener_url()
    {
        $passphrase = get_option(self::LISTENER_PASSPHRASE, false);
        if (!$passphrase) {
            $passphrase = md5(site_url() . time());
            update_option(self::LISTENER_PASSPHRASE, $passphrase);
        }
        return add_query_arg(self::QUERY_VAR, $passphrase, home_url('/'));
    }

    /**
     * CampTix Payment CheckOut : Generate & Submit the payment form
     */
    public function payment_checkout($payment_token)
    {

        if (!$payment_token || empty($payment_token))
            return false;

        if (!in_array($this->camptix_options['currency'], $this->supported_currencies))
            die(__('The selected currency is not supported by this payment method.', 'billplz'));

        $api_key = $this->options['api_key'];
        $collection_id = $this->options['collection_id'];
        $deliver = $this->options['notification'] ? '3' : '0';

        $description = ( $this->camptix_options['event_name'] != "" ) ? $this->camptix_options['event_name'] : get_bloginfo('name');
        $order = $this->get_order($payment_token);
        $order_total = $order['total'];

        $ipn_url = self::get_listener_url();

        $attendees = get_posts(
            array(
                'post_type' => 'tix_attendee',
                'post_status' => 'any',
                'orderby' => 'ID',
                'order' => 'ASC',
                'meta_query' => array(
                    array(
                        'key' => 'tix_payment_token',
                        'compare' => '=',
                        'value' => $payment_token
                    )
                )
            )
        );

        foreach ($attendees as $attendee) {
            $tix_id = get_post(get_post_meta($attendee->ID, 'tix_ticket_id', true));

            // Get Mobile Number
            $attendee_questions = get_post_meta($attendee->ID, 'tix_questions', true); // Array of Attendee Questons
            if ($collection_id != '') { // Check if Setup for Mobile is set?
                $attendee_info_mobile = $attendee_questions[$collection_id];
            } else {
                $attendee_info_mobile = '';
            }

            $attendee_info[] = array(
                $attendee->ID,
                get_post_meta($attendee->ID, 'tix_email', true),
                get_post_meta($attendee->ID, 'tix_first_name', true),
                get_post_meta($attendee->ID, 'tix_last_name', true),
                get_post_meta($attendee->ID, 'tix_ticket_discounted_price', true),
                $tix_id->post_title,
                get_post_meta($attendee->ID, 'tix_access_token', true),
                get_post_meta($attendee->ID, 'tix_edit_token', true),
                $attendee_info_mobile
            ); // array(0=id,1=email,2=first_name,3=last_name,4=tix_amount,5=tix_name,6=access_token,7=edit_token,8=mobile);
        }

        $billplz = new Billplz($api_key);
        $billplz
            ->setAmount($order_total)
            ->setCollection($collection_id)
            ->setDeliver($deliver)
            ->setDescription($description)
            ->setEmail($attendee_info[0][1])
            ->setMobile($attendee_info_mobile)
            ->setName($attendee_info[0][2] . ' ' . $attendee_info[0][3])
            ->setPassbackURL($ipn_url, $ipn_url)
            ->setReference_1($order_id)
            ->setReference_1_Label('ID')
            ->create_bill(true);

        $bill_url = $billplz->getURL();
        $bill_id = $billplz->getID();
        update_option('billplz_camptix_' . $bill_id, $payment_token, false);

        if (!headers_sent()) {
            wp_redirect(esc_url_raw($bill_url));
        } else {
            $stroutput = "Redirecting to Billplz... If you are not redirected, please click <a href=" . '"' . $bill_url . '"' . " target='_self'>Here</a><br />"
                . "<script>location.href = '" . $bill_url . "'</script>";
            echo $stroutput;
        }
        return;
    }

    /**
     * Runs when the user cancels their payment during checkout at Billplz.
     * This will simply tell CampTix to put the created attendee drafts into to Cancelled state.
     */
    function payment_cancel()
    {
        global $camptix;

        $payment_token = ( isset($_REQUEST['tix_payment_token']) ) ? trim($_REQUEST['tix_payment_token']) : '';

        if (!$payment_token)
            die('empty token');
        // Set the associated attendees to cancelled.
        return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED);
    }
}
