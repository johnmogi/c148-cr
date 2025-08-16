<?php
/**
 * Plugin Name: Comeet Slider Helper
 * Description: Enhanced job slider for Comeet with category filtering and RTL support.
 * Version: 2.0.0
 * Author: אביב דיגיטל
 */

if (!defined('ABSPATH')) { exit; }

/**
 * Default job categories with their keywords
 */
function comeet_get_job_categories() {
    $default_categories = [
        'Engineering' => [
            'terms' => ['Engineer', 'Back-End', 'Front-End', 'Developer', 'DevOps', 'SRE', 'Architect'],
            'icon' => 'fas fa-code',
            'color' => '#3498db'
        ],
        'Product & Design' => [
            'terms' => ['Product', 'Designer', 'UX', 'UI', 'Research'],
            'icon' => 'fas fa-paint-brush',
            'color' => '#9b59b6'
        ],
        'Data & Analytics' => [
            'terms' => ['Analyst', 'Data', 'Analytics', 'BI', 'Business Intelligence'],
            'icon' => 'fas fa-chart-line',
            'color' => '#2ecc71'
        ],
        'Business' => [
            'terms' => ['Business', 'Sales', 'Marketing', 'Growth', 'Partnership'],
            'icon' => 'fas fa-briefcase',
            'color' => '#e74c3c'
        ],
        'Operations' => [
            'terms' => ['HR', 'People', 'Talent', 'Recruiter', 'Office Manager'],
            'icon' => 'fas fa-users-cog',
            'color' => '#f39c12'
        ]
    ];

    return apply_filters('comeet_job_categories', $default_categories);
}

/**
 * Categorize a job based on its title
 */
function comeet_categorize_job($job_title) {
    $title = strtolower($job_title);
    $categories = comeet_get_job_categories();
    
    // Check each category's terms
    foreach ($categories as $category_name => $category_data) {
        foreach ($category_data['terms'] as $term) {
            if (stripos($title, strtolower($term)) !== false) {
                return $category_name;
            }
        }
    }
    
    // Check for specific patterns
    if (preg_match('/(front[- ]?end|react|angular|vue|javascript|js)/i', $title)) {
        return 'Engineering';
    }
    
    if (preg_match('/(back[- ]?end|node|python|java|php|ruby|go|scala)/i', $title)) {
        return 'Engineering';
    }
    
    if (preg_match('/(devops|sre|site reliability|cloud|aws|azure|gcp)/i', $title)) {
        return 'Engineering';
    }
    
    if (preg_match('/(data|analytics|analyst|scientist|machine learning|ai|business intelligence)/i', $title)) {
        return 'Data & Analytics';
    }
    
    return 'Other';
}

/**
 * Fetch jobs from Comeet
 */
function comeet_fetch_jobs() {
    $jobs = [];
    
    // Try to get jobs from Comeet plugin if available
    if (class_exists('Comeet')) {
        try {
            $comeet = new Comeet();
            
            // Try different methods to get jobs
            if (method_exists($comeet, 'get_jobs')) {
                $jobs = $comeet->get_jobs();
            } elseif (method_exists($comeet, 'getData')) {
                $data = $comeet->getData();
                $jobs = is_array($data) ? $data : [];
            } elseif (method_exists($comeet, 'comeet_content')) {
                $content = $comeet->comeet_content();
                $jobs = is_array($content) ? $content : [];
            }
        } catch (Exception $e) {
            error_log('Comeet Slider Error: ' . $e->getMessage());
        }
    }
    
    // If no jobs from plugin, try to scrape the careers page
    if (empty($jobs)) {
        $jobs = comeet_scrape_jobs();
    }
    
    // Clean and categorize jobs
    foreach ($jobs as &$job) {
        // Clean up job title - remove extra whitespace and formatting
        if (!empty($job['title'])) {
            $job['title'] = trim(preg_replace('/\s+/', ' ', $job['title']));
            // Extract just the job title (before location/type info)
            $title_parts = explode('·', $job['title']);
            if (count($title_parts) > 1) {
                $job['title'] = trim($title_parts[0]);
                // Extract location and type from the rest
                if (empty($job['location']) && count($title_parts) > 1) {
                    $location_type = trim($title_parts[1]);
                    if (strpos($location_type, 'Office') !== false || strpos($location_type, 'Hybrid') !== false) {
                        $job['location'] = $location_type;
                    }
                }
                if (empty($job['type']) && count($title_parts) > 2) {
                    $job['type'] = trim($title_parts[2]);
                }
            }
        }
        
        $job['category'] = comeet_categorize_job($job['title'] ?? '');
    }
    
    return apply_filters('comeet_fetched_jobs', $jobs);
}

/**
 * Scrape jobs from the careers page as fallback
 */
function comeet_scrape_jobs() {
    $jobs = [];
    $careers_url = apply_filters('comeet_careers_url', home_url('/careers/'));
    
    $response = wp_remote_get($careers_url, [
        'timeout' => 30,
        'sslverify' => false,
        'httpversion' => '1.1',
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]
    ]);
    
    if (!is_wp_error($response) && $response['response']['code'] === 200) {
        $html = wp_remote_retrieve_body($response);
        
        if (!empty($html)) {
            $dom = new DOMDocument();
            @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            $xpath = new DOMXPath($dom);
            
            // Try different selectors to find job listings
            $selectors = [
                '//*[contains(concat(" ", normalize-space(@class), " "), " comeet-position ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " job-item ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " position ")]'
            ];
            
            $job_elements = [];
            foreach ($selectors as $selector) {
                $elements = $xpath->query($selector);
                if ($elements && $elements->length > 0) {
                    $job_elements = $elements;
                    break;
                }
            }
            
            // Process found job elements
            if (!empty($job_elements)) {
                foreach ($job_elements as $element) {
                    $job = [
                        'title' => '',
                        'location' => '',
                        'type' => '',
                        'link' => ''
                    ];
                    
                    // Get job title
                    $title = $xpath->query('.//*[contains(@class, "title")]', $element);
                    if ($title && $title->length > 0) {
                        $job['title'] = trim($title->item(0)->nodeValue);
                    } else {
                        $job['title'] = trim($element->nodeValue);
                    }
                    
                    // Get job link
                    if ($element->tagName === 'a') {
                        $job['link'] = $element->getAttribute('href');
                        if (!preg_match('/^https?:\/\//', $job['link'])) {
                            $job['link'] = home_url($job['link']);
                        }
                    }
                    
                    // Only add if we have at least a title
                    if (!empty($job['title'])) {
                        $jobs[] = $job;
                    }
                }
            }
        }
    }
    
    return $jobs;
}

/**
 * Shortcode to display the job slider with filters
 */
function comeet_job_slider_shortcode($atts) {
    $atts = shortcode_atts([
        'show_filters' => 'yes',
        'default_category' => '',
        'limit' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ], $atts, 'comeet_job_slider');
    
    // Get jobs
    $jobs = comeet_fetch_jobs();
    
    // Debug: Log job fetching results
    error_log('FRESH BUILD: Found ' . count($jobs) . ' jobs');
    
    if (empty($jobs)) {
        error_log('FRESH BUILD: No jobs found - using test data');
        // Add test jobs for debugging
        $jobs = [
            [
                'title' => 'Senior Software Engineer',
                'location' => 'Tel Aviv',
                'type' => 'Full-time',
                'link' => '#test1',
                'category' => 'Engineering'
            ],
            [
                'title' => 'Product Manager',
                'location' => 'Jerusalem',
                'type' => 'Full-time',
                'link' => '#test2',
                'category' => 'Product'
            ],
            [
                'title' => 'Data Scientist',
                'location' => 'Hybrid',
                'type' => 'Full-time',
                'link' => '#test3',
                'category' => 'Data'
            ]
        ];
    }
    
    // Group jobs by category for filters
    $categories = [];
    foreach ($jobs as $job) {
        $category = $job['category'] ?? 'Other';
        if (!isset($categories[$category])) {
            $categories[$category] = [];
        }
        $categories[$category][] = $job;
    }
    
    // Filter categories with at least 3 jobs and limit to 5
    $categories = array_filter($categories, function($jobs) {
        return count($jobs) >= 3;
    });
    $categories = array_slice($categories, 0, 5, true);
    
    // Category info with colors and icons
    $category_info = [
        'Engineering' => ['icon' => 'fas fa-code', 'color' => '#6c5ce7'],
        'Data & Analytics' => ['icon' => 'fas fa-chart-bar', 'color' => '#00b894'],
        'Product & Design' => ['icon' => 'fas fa-palette', 'color' => '#e17055'],
        'Management' => ['icon' => 'fas fa-users', 'color' => '#fdcb6e'],
        'Other' => ['icon' => 'fas fa-briefcase', 'color' => '#74b9ff']
    ];
    
    // Simple, clean output with aggressive visibility
    $output = '<div class="fresh-jobs-wrapper">';
    $output .= '<style>
        .fresh-jobs-wrapper {
            background: #1a0d32 !important;
            padding: 30px !important;
            border-radius: 15px !important;
            margin: 20px 0 !important;
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            position: relative !important;
            z-index: 1000 !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
        .fresh-filter-buttons {
            display: flex !important;
            flex-wrap: wrap !important;
            gap: 15px !important;
            margin-bottom: 30px !important;
            justify-content: center !important;
            visibility: visible !important;
        }
        .fresh-filter-btn {
            background: rgba(255,255,255,0.1) !important;
            border: 2px solid rgba(255,255,255,0.3) !important;
            color: white !important;
            padding: 12px 20px !important;
            border-radius: 25px !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
            font-size: 14px !important;
            font-weight: bold !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            visibility: visible !important;
        }
        .fresh-filter-btn:hover,
        .fresh-filter-btn.active {
            background: rgba(255,255,255,0.2) !important;
            border-color: white !important;
            transform: translateY(-2px) !important;
        }
        .fresh-filter-btn i {
            font-size: 16px !important;
        }
        .fresh-jobs-title {
            color: white !important;
            text-align: center !important;
            font-size: 28px !important;
            margin-bottom: 30px !important;
            display: block !important;
            visibility: visible !important;
            font-weight: bold !important;
        }
        .fresh-jobs-grid {
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)) !important;
            gap: 20px !important;
            visibility: visible !important;
        }
        .fresh-job-card {
            background: #2a184a !important;
            border: 2px solid rgba(255,255,255,0.2) !important;
            border-radius: 12px !important;
            padding: 25px !important;
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            transition: transform 0.3s ease !important;
        }
        .fresh-job-card:hover {
            transform: translateY(-5px) !important;
            border-color: rgba(255,255,255,0.4) !important;
        }
        .fresh-job-title {
            color: white !important;
            font-size: 20px !important;
            font-weight: bold !important;
            margin: 0 0 15px 0 !important;
            display: block !important;
            visibility: visible !important;
            line-height: 1.4 !important;
        }
        .fresh-job-meta {
            color: #ccc !important;
            margin: 8px 0 !important;
            display: block !important;
            visibility: visible !important;
            font-size: 14px !important;
        }
        .fresh-job-link {
            display: inline-block !important;
            background: white !important;
            color: #1a0d32 !important;
            padding: 12px 24px !important;
            border-radius: 25px !important;
            text-decoration: none !important;
            margin-top: 20px !important;
            font-weight: bold !important;
            transition: all 0.3s ease !important;
            visibility: visible !important;
        }
        .fresh-job-link:hover {
            background: #f0f0f0 !important;
            transform: scale(1.05) !important;
        }
        .fresh-job-card.hidden {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            height: 0 !important;
            width: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            position: absolute !important;
            left: -10000px !important;
            top: -10000px !important;
            overflow: hidden !important;
        }
    </style>';
    
    $output .= '<h2 class="fresh-jobs-title">המשרות שלנו (' . count($jobs) . ')</h2>';
    
    // Add filter buttons if we have categories
    if (!empty($categories)) {
        $output .= '<div class="fresh-filter-buttons">';
        $output .= '<button class="fresh-filter-btn active" data-category="all">';
        $output .= '<i class="fas fa-briefcase"></i> כל המשרות (' . count($jobs) . ')';
        $output .= '</button>';
        
        foreach ($categories as $category => $category_jobs) {
            $info = $category_info[$category] ?? ['icon' => 'fas fa-tag', 'color' => '#74b9ff'];
            $output .= '<button class="fresh-filter-btn" data-category="' . esc_attr($category) . '">';
            $output .= '<i class="' . esc_attr($info['icon']) . '"></i> ';
            $output .= esc_html($category) . ' (' . count($category_jobs) . ')';
            $output .= '</button>';
        }
        $output .= '</div>';
    }
    
    $output .= '<div class="fresh-jobs-grid">';
    
    foreach ($jobs as $job) {
        $output .= '<div class="fresh-job-card" data-category="' . esc_attr($job['category'] ?? 'Other') . '">';
        $output .= '<h3 class="fresh-job-title">' . esc_html($job['title']) . '</h3>';
        
        if (!empty($job['type'])) {
            $output .= '<div class="fresh-job-meta">סוג: ' . esc_html($job['type']) . '</div>';
        }
        
        if (!empty($job['location'])) {
            $output .= '<div class="fresh-job-meta">מיקום: ' . esc_html($job['location']) . '</div>';
        }
        
        if (!empty($job['category'])) {
            $output .= '<div class="fresh-job-meta">קטגוריה: ' . esc_html($job['category']) . '</div>';
        }
        
        $output .= '<a href="' . esc_url($job['link']) . '" class="fresh-job-link" target="_blank">לפרטים והגשת מועמדות</a>';
        $output .= '</div>';
    }
    
    $output .= '</div>';
    $output .= '</div>';
    
    // Add JavaScript for filters and confirmation
    $output .= '<script>
        console.log("🎉 FRESH BUILD LOADED - ' . count($jobs) . ' JOBS DISPLAYED");
        
        // Use unique ID to prevent duplicate execution
        var uniqueId = "fresh-jobs-" + Math.random().toString(36).substr(2, 9);
        
        function initFreshJobFilters() {
            var wrapper = document.querySelector(".fresh-jobs-wrapper");
            if (wrapper && !wrapper.dataset.initialized) {
                wrapper.dataset.initialized = "true";
                console.log("✅ Jobs wrapper found and visible");
                wrapper.style.border = "3px solid lime";
                setTimeout(function() {
                    wrapper.style.border = "2px solid rgba(255,255,255,0.2)";
                }, 2000);
                
                // Add filter functionality
                var filterBtns = wrapper.querySelectorAll(".fresh-filter-btn");
                var jobCards = wrapper.querySelectorAll(".fresh-job-card");
                
                console.log("Found " + filterBtns.length + " filter buttons and " + jobCards.length + " job cards");
                
                filterBtns.forEach(function(btn, index) {
                    btn.addEventListener("click", function(e) {
                        e.preventDefault();
                        var category = this.getAttribute("data-category");
                        
                        console.log("🔍 Filtering by: " + category);
                        
                        // Update active button
                        filterBtns.forEach(function(b) { 
                            b.classList.remove("active"); 
                            b.style.background = "rgba(255,255,255,0.1)";
                        });
                        this.classList.add("active");
                        this.style.background = "rgba(255,255,255,0.3)";
                        
                        // Filter job cards - completely remove from layout
                        var visibleCount = 0;
                        jobCards.forEach(function(card) {
                            var cardCategory = card.getAttribute("data-category");
                            if (category === "all" || cardCategory === category) {
                                // Show the card
                                card.style.display = "block";
                                card.style.position = "relative";
                                card.style.left = "auto";
                                card.style.top = "auto";
                                card.style.width = "auto";
                                card.style.height = "auto";
                                card.style.visibility = "visible";
                                card.style.opacity = "1";
                                card.style.transform = "scale(1)";
                                card.style.margin = "";
                                card.style.padding = "";
                                visibleCount++;
                            } else {
                                // Completely remove from layout
                                card.style.display = "none !important";
                                card.style.position = "absolute";
                                card.style.left = "-9999px";
                                card.style.top = "-9999px";
                                card.style.width = "0";
                                card.style.height = "0";
                                card.style.visibility = "hidden";
                                card.style.opacity = "0";
                                card.style.overflow = "hidden";
                                card.style.margin = "0";
                                card.style.padding = "0";
                                card.style.border = "none";
                            }
                        });
                        
                        console.log("✅ Showing " + visibleCount + " jobs for category: " + category);
                    });
                });
                
                console.log("🎛️ Filter buttons initialized successfully");
            }
        }
        
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", initFreshJobFilters);
        } else {
            initFreshJobFilters();
        }
    </script>';
    
    return $output;
}

// Register the shortcode
add_shortcode('comeet_job_slider', 'comeet_job_slider_shortcode');

// NEW FRESH SHORTCODE - Use this one!
add_shortcode('fresh_jobs', 'comeet_job_slider_shortcode');

// ULTRA STABLE JOBS SHORTCODE - Maximum reliability
function ultra_stable_jobs_shortcode($atts = []) {
    // Error handling wrapper
    try {
        // Get jobs with fallback
        $jobs = [];
        if (function_exists('comeet_fetch_jobs')) {
            $jobs = comeet_fetch_jobs();
        }
        
        // Fallback if no jobs found
        if (empty($jobs)) {
            error_log('ULTRA STABLE: No jobs found, using fallback data');
            $jobs = [
                ['title' => 'Senior Software Engineer', 'location' => 'Jerusalem', 'type' => 'Senior', 'link' => '#', 'category' => 'Engineering'],
                ['title' => 'Data Scientist', 'location' => 'Jerusalem', 'type' => 'Senior', 'link' => '#', 'category' => 'Data & Analytics'],
                ['title' => 'Product Manager', 'location' => 'Jerusalem', 'type' => 'Management', 'link' => '#', 'category' => 'Product & Design']
            ];
        }
        
        // Group jobs safely
        $categories = [];
        foreach ($jobs as $job) {
            $cat = isset($job['category']) ? $job['category'] : 'Other';
            if (!isset($categories[$cat])) $categories[$cat] = [];
            $categories[$cat][] = $job;
        }
        
        // Filter categories with 3+ jobs
        $categories = array_filter($categories, function($jobs) { return count($jobs) >= 3; });
        $categories = array_slice($categories, 0, 5, true);
        
        // Unique ID for this instance
        $unique_id = 'ultra-jobs-' . uniqid();
        $timestamp = time();
        
        // Build output with maximum stability
        $output = '<div class="ultra-jobs-container" id="' . $unique_id . '" data-timestamp="' . $timestamp . '">';
        
        // Inline CSS with maximum specificity
        $output .= '<style>
            #' . $unique_id . ' {
                background: linear-gradient(135deg, #1a0d32 0%, #2a184a 100%) !important;
                padding: 40px !important;
                border-radius: 20px !important;
                margin: 30px auto !important;
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
                position: relative !important;
                z-index: 1000 !important;
                width: 100% !important;
                max-width: 1200px !important;
                box-sizing: border-box !important;
                font-family: "Heebo", Arial, sans-serif !important;
                direction: rtl !important;
                box-shadow: 0 20px 40px rgba(0,0,0,0.3) !important;
            }
            #' . $unique_id . ' .ultra-title {
                color: white !important;
                text-align: center !important;
                font-size: 32px !important;
                font-weight: bold !important;
                margin: 0 0 40px 0 !important;
                display: block !important;
                visibility: visible !important;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.5) !important;
            }
            #' . $unique_id . ' .ultra-filters {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 15px !important;
                justify-content: center !important;
                margin-bottom: 40px !important;
                visibility: visible !important;
            }
            #' . $unique_id . ' .ultra-filter-btn {
                background: rgba(255,255,255,0.15) !important;
                border: 2px solid rgba(255,255,255,0.4) !important;
                color: white !important;
                padding: 15px 25px !important;
                border-radius: 30px !important;
                cursor: pointer !important;
                font-size: 16px !important;
                font-weight: bold !important;
                transition: all 0.3s ease !important;
                display: inline-flex !important;
                align-items: center !important;
                gap: 10px !important;
                visibility: visible !important;
                text-decoration: none !important;
                outline: none !important;
            }
            #' . $unique_id . ' .ultra-filter-btn:hover,
            #' . $unique_id . ' .ultra-filter-btn.active {
                background: rgba(255,255,255,0.3) !important;
                border-color: white !important;
                transform: translateY(-3px) !important;
                box-shadow: 0 10px 20px rgba(0,0,0,0.3) !important;
            }
            #' . $unique_id . ' .ultra-jobs-grid {
                display: grid !important;
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)) !important;
                gap: 25px !important;
                visibility: visible !important;
            }
            #' . $unique_id . ' .ultra-job-card {
                background: rgba(255,255,255,0.1) !important;
                border: 2px solid rgba(255,255,255,0.2) !important;
                border-radius: 15px !important;
                padding: 30px !important;
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
                transition: all 0.3s ease !important;
                backdrop-filter: blur(10px) !important;
            }
            #' . $unique_id . ' .ultra-job-card:hover {
                transform: translateY(-8px) !important;
                border-color: rgba(255,255,255,0.6) !important;
                box-shadow: 0 15px 30px rgba(0,0,0,0.4) !important;
            }
            #' . $unique_id . ' .ultra-job-title {
                color: white !important;
                font-size: 22px !important;
                font-weight: bold !important;
                margin: 0 0 20px 0 !important;
                display: block !important;
                visibility: visible !important;
                line-height: 1.4 !important;
            }
            #' . $unique_id . ' .ultra-job-meta {
                color: rgba(255,255,255,0.8) !important;
                margin: 10px 0 !important;
                display: block !important;
                visibility: visible !important;
                font-size: 16px !important;
            }
            #' . $unique_id . ' .ultra-job-link {
                display: inline-block !important;
                background: white !important;
                color: #1a0d32 !important;
                padding: 15px 30px !important;
                border-radius: 30px !important;
                text-decoration: none !important;
                margin-top: 25px !important;
                font-weight: bold !important;
                font-size: 16px !important;
                transition: all 0.3s ease !important;
                visibility: visible !important;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2) !important;
            }
            #' . $unique_id . ' .ultra-job-link:hover {
                background: #f0f0f0 !important;
                transform: scale(1.05) !important;
                box-shadow: 0 8px 25px rgba(0,0,0,0.3) !important;
            }
            #' . $unique_id . ' .ultra-job-card.hidden {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                position: absolute !important;
                left: -10000px !important;
                top: -10000px !important;
            }
        </style>';
        
        // Title
        $output .= '<h2 class="ultra-title">המשרות שלנו (' . count($jobs) . ')</h2>';
        
        // Filters
        if (!empty($categories)) {
            $output .= '<div class="ultra-filters">';
            $output .= '<button class="ultra-filter-btn active" data-category="all">';
            $output .= '<i class="fas fa-briefcase"></i> כל המשרות (' . count($jobs) . ')';
            $output .= '</button>';
            
            $icons = [
                'Engineering' => 'fas fa-code',
                'Data & Analytics' => 'fas fa-chart-bar', 
                'Product & Design' => 'fas fa-palette',
                'Management' => 'fas fa-users',
                'Other' => 'fas fa-briefcase'
            ];
            
            foreach ($categories as $category => $category_jobs) {
                $icon = isset($icons[$category]) ? $icons[$category] : 'fas fa-tag';
                $output .= '<button class="ultra-filter-btn" data-category="' . esc_attr($category) . '">';
                $output .= '<i class="' . $icon . '"></i> ' . esc_html($category) . ' (' . count($category_jobs) . ')';
                $output .= '</button>';
            }
            $output .= '</div>';
        }
        
        // Jobs grid
        $output .= '<div class="ultra-jobs-grid">';
        foreach ($jobs as $job) {
            $category = isset($job['category']) ? $job['category'] : 'Other';
            $output .= '<div class="ultra-job-card" data-category="' . esc_attr($category) . '">';
            $output .= '<h3 class="ultra-job-title">' . esc_html($job['title']) . '</h3>';
            
            if (!empty($job['type'])) {
                $output .= '<div class="ultra-job-meta"><strong>סוג:</strong> ' . esc_html($job['type']) . '</div>';
            }
            if (!empty($job['location'])) {
                $output .= '<div class="ultra-job-meta"><strong>מיקום:</strong> ' . esc_html($job['location']) . '</div>';
            }
            if (!empty($job['category'])) {
                $output .= '<div class="ultra-job-meta"><strong>קטגוריה:</strong> ' . esc_html($job['category']) . '</div>';
            }
            
            $output .= '<a href="' . esc_url($job['link']) . '" class="ultra-job-link" target="_blank">לפרטים והגשת מועמדות</a>';
            $output .= '</div>';
        }
        $output .= '</div></div>';
        
        // Ultra-stable JavaScript
        $output .= '<script>
        (function() {
            var containerId = "' . $unique_id . '";
            var initAttempts = 0;
            var maxAttempts = 10;
            
            function initUltraJobs() {
                initAttempts++;
                console.log("🚀 ULTRA STABLE INIT ATTEMPT " + initAttempts + " for " + containerId);
                
                var container = document.getElementById(containerId);
                if (!container) {
                    if (initAttempts < maxAttempts) {
                        setTimeout(initUltraJobs, 500);
                    }
                    return;
                }
                
                if (container.dataset.initialized) {
                    console.log("⚠️ Already initialized: " + containerId);
                    return;
                }
                
                container.dataset.initialized = "true";
                console.log("✅ ULTRA STABLE JOBS INITIALIZED: " + containerId);
                
                // Visual confirmation
                container.style.border = "3px solid lime";
                setTimeout(function() {
                    container.style.border = "2px solid rgba(255,255,255,0.2)";
                }, 2000);
                
                // Filter functionality
                var filterBtns = container.querySelectorAll(".ultra-filter-btn");
                var jobCards = container.querySelectorAll(".ultra-job-card");
                
                console.log("Found " + filterBtns.length + " filters and " + jobCards.length + " jobs");
                
                filterBtns.forEach(function(btn) {
                    btn.addEventListener("click", function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        var category = this.getAttribute("data-category");
                        console.log("🔍 ULTRA FILTER: " + category);
                        
                        // Update active state
                        filterBtns.forEach(function(b) {
                            b.classList.remove("active");
                            b.style.background = "rgba(255,255,255,0.15)";
                        });
                        this.classList.add("active");
                        this.style.background = "rgba(255,255,255,0.3)";
                        
                        // Filter cards
                        var visibleCount = 0;
                        jobCards.forEach(function(card) {
                            var cardCategory = card.getAttribute("data-category");
                            if (category === "all" || cardCategory === category) {
                                card.classList.remove("hidden");
                                card.style.display = "block";
                                card.style.visibility = "visible";
                                card.style.opacity = "1";
                                card.style.position = "relative";
                                card.style.left = "auto";
                                card.style.top = "auto";
                                visibleCount++;
                            } else {
                                card.classList.add("hidden");
                                card.style.display = "none";
                                card.style.visibility = "hidden";
                                card.style.opacity = "0";
                                card.style.position = "absolute";
                                card.style.left = "-10000px";
                                card.style.top = "-10000px";
                            }
                        });
                        
                        console.log("✅ ULTRA FILTER COMPLETE: " + visibleCount + " jobs visible");
                    });
                });
            }
            
            // Multiple initialization strategies
            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", initUltraJobs);
            } else {
                initUltraJobs();
            }
            
            // Backup initialization
            setTimeout(initUltraJobs, 1000);
            setTimeout(initUltraJobs, 3000);
        })();
        </script>';
        
        return $output;
        
    } catch (Exception $e) {
        error_log('ULTRA STABLE SHORTCODE ERROR: ' . $e->getMessage());
        return '<div style="background: red; color: white; padding: 20px; margin: 20px 0;">שגיאה בטעינת המשרות. אנא נסה שוב מאוחר יותר.</div>';
    }
}
add_shortcode('ultra_jobs', 'ultra_stable_jobs_shortcode');

// Add diagnostic shortcodes for debugging
function test_visibility_shortcode() {
    return '<div style="position: fixed; top: 0; left: 0; width: 100%; height: 100px; background: red; color: white; z-index: 99999; font-size: 30px; text-align: center; padding: 20px;">🚨 TEST SHORTCODE WORKING! 🚨</div>';
}
add_shortcode('test_visibility', 'test_visibility_shortcode');

// Simple diagnostic shortcode
function debug_shortcode() {
    $output = '<div style="background: orange; color: black; padding: 20px; margin: 20px 0; border: 5px solid red; font-size: 18px; font-weight: bold;">';
    $output .= '🔍 DEBUG SHORTCODE ACTIVE<br>';
    $output .= 'Time: ' . date('H:i:s') . '<br>';
    $output .= 'Page: ' . get_the_title() . '<br>';
    $output .= 'URL: ' . get_permalink() . '<br>';
    $output .= 'Elementor: ' . (class_exists('Elementor\Plugin') ? 'YES' : 'NO') . '<br>';
    $output .= 'Comeet Plugin: ' . (class_exists('Comeet') ? 'YES' : 'NO');
    $output .= '</div>';
    
    $output .= '<script>console.log("🔍 DEBUG SHORTCODE EXECUTED ON: " + window.location.href);</script>';
    
    return $output;
}
add_shortcode('debug_info', 'debug_shortcode');

// Minimal jobs shortcode for testing
function minimal_jobs_shortcode() {
    $jobs = comeet_fetch_jobs();
    $count = count($jobs);
    
    $output = '<div style="background: green; color: white; padding: 30px; margin: 20px 0; border-radius: 10px; text-align: center;">';
    $output .= '<h2>MINIMAL JOBS TEST</h2>';
    $output .= '<p>Found ' . $count . ' jobs</p>';
    
    if ($count > 0) {
        $output .= '<ul style="text-align: left; max-height: 200px; overflow-y: auto;">';
        foreach (array_slice($jobs, 0, 5) as $job) {
            $output .= '<li>' . esc_html($job['title']) . '</li>';
        }
        if ($count > 5) {
            $output .= '<li>... and ' . ($count - 5) . ' more jobs</li>';
        }
        $output .= '</ul>';
    }
    
    $output .= '</div>';
    $output .= '<script>console.log("✅ MINIMAL JOBS SHORTCODE: ' . $count . ' jobs found");</script>';
    
    return $output;
}
add_shortcode('minimal_jobs', 'minimal_jobs_shortcode');

add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('heebo-font','https://fonts.googleapis.com/css2?family=Heebo:wght@400;600;700&display=swap',[],null);
    wp_enqueue_style('font-awesome','https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',[],null);
    wp_enqueue_style('swiper-css','https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.css',[],null);
    wp_enqueue_script('swiper-js','https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.js',[],null,true);
    wp_enqueue_style('comeet-slider-helper', plugin_dir_url(__FILE__) . 'assets/comeet-slider.css', ['heebo-font','swiper-css'], null);
    wp_enqueue_script('comeet-slider-helper', plugin_dir_url(__FILE__) . 'assets/comeet-slider.js', ['swiper-js'], null, true);
}, 20);
