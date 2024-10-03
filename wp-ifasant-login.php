<?php

/**
 * Plugin Name:     Wp Ifasant Login
 * Plugin URI:      PLUGIN SITE HERE
 * Description:     PLUGIN DESCRIPTION HERE
 * Author:          YOUR NAME HERE
 * Author URI:      YOUR SITE HERE
 * Text Domain:     wp-ifasant-login
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Wp_Ifasant_Login
 */

// Your code starts here.
defined('ABSPATH') or die();


function custom_login_with_mobile($user, $username, $password)
{


    $login_url = wc_get_page_permalink('myaccount');
    $login_url = add_query_arg('mobile', $username, $login_url);

    // REQUEST FOR OTP CODE , USERNAME IS MOBILE 
    if (!empty($username) &&     wp_verify_nonce($password, 'send-otp')) {

        // Check if the username input is a mobile number
        if (!preg_match('/^((0?9)|(\+?989))((14)|(13)|(12)|(19)|(18)|(17)|(15)|(16)|(11)|(10)|(90)|(91)|(92)|(93)|(94)|(95)|(96)|(32)|(30)|(33)|(35)|(36)|(37)|(38)|(39)|(00)|(01)|(02)|(03)|(04)|(05)|(41)|(20)|(21)|(22)|(23)|(31)|(34)|(9910)|(9911)|(9913)|(9914)|(9999)|(999)|(990)|(9810)|(9811)|(9812)|(9813)|(9814)|(9815)|(9816)|(9817)|(998))\d{7}$/', $username)) {

            $_POST['status'] = -1;
            wc_add_notice(__('شماره موبایل اشتباه است', 'woocommerce'), 'error');
            return new WP_Error('wrong_mobile_number', __('شماره موبایل اشتباه است', 'woocommerce'));
        }

        $response = create_user_if_does_not_exist_and_update_password_if_user_exists_return_user($username);

        $user = $response['user'];
        $otp = $response['otp'];

        if (is_within_2min($user->ID)) {
            // status=2 stay in password place
            $_POST['status'] = 2;
            wc_add_notice(__('کد قبلی تا دو دقیقه معتبر است برای درخواست کد جدید لطفا تا اتمام زمان کد قبلی منتظر بمانید', 'woocommerce'), 'error');
            return new WP_Error('requesting_again_within_2_min', __('کد قبلی تا دو دقیقه معتبر است برای درخواست کد جدید لطفا تا اتمام زمان کد قبلی منتظر بمانید', 'woocommerce'));
        }

        set_otp_datetime_to_user_metadata($user->ID);

        // Optionally, send an email to the user with their new account details
        send_otp_to_mobile($username,  $otp);


        $_POST['username'] = $username;
        $_POST['status'] = 1;
        $_POST['seconds'] = 120;

        wc_clear_notices();
        wc_add_notice(__('رمز پویا به شماره موبایل شما ارسال شد لطفا آن را در زیر وارد نمایید', 'woocommerce'), 'success');
        return null;
    }



    if (empty($username) || empty($password)) {
        return null;
    }

    if (!preg_match('/^((0?9)|(\+?989))((14)|(13)|(12)|(19)|(18)|(17)|(15)|(16)|(11)|(10)|(90)|(91)|(92)|(93)|(94)|(95)|(96)|(32)|(30)|(33)|(35)|(36)|(37)|(38)|(39)|(00)|(01)|(02)|(03)|(04)|(05)|(41)|(20)|(21)|(22)|(23)|(31)|(34)|(9910)|(9911)|(9913)|(9914)|(9999)|(999)|(990)|(9810)|(9811)|(9812)|(9813)|(9814)|(9815)|(9816)|(9817)|(998))\d{7}$/', $username)) {

        $_POST['status'] = -1;
        wc_add_notice(__('شماره موبایل اشتباه است', 'woocommerce'), 'error');
        return new WP_Error('wrong_mobile_number', __('شماره موبایل اشتباه است', 'woocommerce'));
    }

    // Check if the username input is a mobile number
    $user = get_user_by('login', $username);
    
    if (!is_within_2min($user->ID)) {
        // status=2 stay in password place
        $_POST['status'] = -1;
        wc_add_notice(__(' کد پویا منقضی شده است لطفا مجدد ورود نمایید', 'woocommerce'), 'error');
        return null;
    }

    // Proceed with the normal authentication process
    $resp = wp_authenticate_username_password(null, $username, $password);
    if ($resp instanceof WP_Error) {
        $_POST['status'] = 2;
        wc_add_notice(__('رمز اشتباه است', 'woocommerce'), 'error');
        return $resp;
    } else {
        return $resp;
    }
}


add_filter('authenticate', 'custom_login_with_mobile', 1, 3);




function create_user_if_does_not_exist_and_update_password_if_user_exists_return_user($username)
{

    $user = get_user_by('login', $username);
    $random_password = rand(1000, 9999);

    // Check if user exists
    if ($user) {
        if (!is_within_2min($user->ID)) {
            wp_set_password($random_password,$user->ID );
        }
    } else {

        // Create a new user if one does not exist
        $user_id = wp_create_user($username, $random_password); // Using mobile number as username and fake email

        if (!is_wp_error($user_id)) {
        } else {
            // Handle user creation error
            return new WP_Error('user_creation_failed', __('Failed to create a new user.', 'woocommerce'));
        }

        $user = get_user_by('id', $user_id);


        return  array(
            'user' => $user,
            'otp' => $random_password
        );
    }
    return  array(
        'user' => $user,
        'otp' => $random_password
    );
}

function is_within_2min($user_id)
{
    $otp_sent_datetime = get_user_meta($user_id, 'last_otp_datetime', true);

    if (empty($otp_sent_datetime)) {
        return false;
    }

    $datetime  = DateTime::createFromFormat('Y-m-d H:i:s', $otp_sent_datetime);

    // Get the current date and time
    $now = DateTime::createFromFormat('Y-m-d H:i:s', current_datetime()->format('Y-m-d H:i:s'));

    $interval = $now->diff($datetime);

    $seconds = ($interval->days * 24 * 60 * 60)
        + ($interval->h * 60 * 60)
        + ($interval->i * 60)
        + $interval->s;

    $_POST['seconds'] = 120 - $seconds;

    if ($seconds <= 120) {
        //The time difference is within 2 minutes
        return true;
    }
    //The time difference exceeds 2 minutes.
    return false;
}


function set_otp_datetime_to_user_metadata($user_id)
{

    $current_datetime = current_datetime()->format('Y-m-d H:i:s');

    // Update the last_otp_datetime meta with the mobile number
    update_user_meta($user_id, 'last_otp_datetime', $current_datetime);
}



function send_otp_to_mobile($username, $code) {

    call_rest_api_post("https://api.kavenegar.com/v1/44726D4A4F37714C3134494F7039646E4453313237673D3D/verify/lookup.json?receptor=$username&token=$code&token2=&token3=&template=login");

}


function call_rest_api_post($url) {

    // Define the POST request arguments
    $args = array(
        'body' => array(
        ),
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
    );

    // Make the POST request
    $response = wp_remote_post($url, $args);

    // Check for errors
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        return new WP_Error("Request failed: $error_message");
    }

    // Get the response body
    $body = wp_remote_retrieve_body($response);

    // Decode the JSON response
    $data = json_decode($body, true);

    // Handle the response data
    return $data;
}






add_filter('woocommerce_billing_fields', 'make_email_field_optional');

function make_email_field_optional($fields) {
   // Billing email field. 
	if( isset( $fields['billing_email'] ) ) {
		$fields['billing_email']['required'] = false; 
	}

	return $fields;
}