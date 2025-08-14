<?php
/**
 * Plugin Name: Comeet Slider Helper
 * Description: Swiper slider for Comeet jobs under #careers with Heebo font and stable bottom-centered arrows.
 * Version: 1.7.1
 * Author: אביב דיגיטל
 */
if (!defined('ABSPATH')) { exit; }

add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('heebo-font','https://fonts.googleapis.com/css2?family=Heebo:wght@400;600;700&display=swap',[],null);
    wp_enqueue_style('swiper-css','https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.css',[],null);
    wp_enqueue_script('swiper-js','https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.js',[],null,true);
    wp_enqueue_style('comeet-slider-helper', plugin_dir_url(__FILE__) . 'assets/comeet-slider.css', ['heebo-font','swiper-css'], '1.7.1');
    wp_enqueue_script('comeet-slider-helper', plugin_dir_url(__FILE__) . 'assets/comeet-slider.js', ['swiper-js'], '1.7.1', true);
}, 20);
