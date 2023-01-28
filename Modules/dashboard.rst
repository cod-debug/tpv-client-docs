Main Dashboard
+++++++++++++++

This module is composed of two sub-modules 
Dashboard
=================

Routes
---------------------

.. note:: This code is defining a set of routes for a dashboard in our Laravel application. The routes are using the ``Route`` facade provided by Laravel and the ``get`` method to define the routes that will handle ``HTTP GET`` requests. Each route maps a URL to a specific method within a ``controller``.

.. code-block:: php
    :caption: routes of the dashboard `routes/web.php`
    
    use App\Models\ShortUri;
    ...
    use Illuminate\Support\Facades\Route;
    ...

    ...
    Route::get('dashboard', 'HomeController@index')->name('home')->middleware(['auth']);
    Route::get('dashboard/sales_no_sales', 'HomeController@sales_no_sales');
    Route::get('dashboard/sales_no_sales_dataset', 'HomeController@sales_no_sales_dataset');
    Route::get('dashboard/top_sale_agents', 'HomeController@top_sale_agents');
    Route::get('dashboard/top_sold_products', 'HomeController@top_sold_products');
    Route::get('dashboard/no_sale_dispositions', 'HomeController@no_sale_dispositions');
    Route::get('dashboard/sales_by_vendor', 'HomeController@sales_by_vendor');
    Route::get('dashboard/sales_by_day_of_week', 'HomeController@sales_by_day_of_week');
    Route::get('dashboard/get_states_by_brand', 'HomeController@get_states_by_brand');
    Route::get('dashboard/get_good_sales_by_brand', 'HomeController@get_good_sales_by_brand');
    Route::get('dashboard/get_brand_states', 'HomeController@get_brand_states');
    Route::get('dashboard/get_good_sales_by_county/{state}', 'HomeController@get_good_sales_by_county');
    Route::get('dashboard/salesAgentDashboard', 'HomeController@salesAgentDashboard');
    Route::get('dashboard/getActiveAgents', 'HomeController@getActiveAgents');
    Route::get('dashboard/avg_sales_per_day', 'HomeController@avg_sales_per_day');
    Route::get('dashboard/avg_agents_active_per_day', 'HomeController@avg_agents_active_per_day');
    Route::get('dashboard/avg_daily_sales_per_agent', 'HomeController@avg_daily_sales_per_agent');
    Route::get('dashboard/sales_no_sales_by_hour', 'HomeController@sales_no_sales_by_hour');
    Route::get('dashboard/last_seven_days_s_ns', 'HomeController@last_seven_days_s_ns');
    ...

Maps the URL ``dashboard`` to the index method of the ``HomeController`` and it uses the name method to give the route a name of home and the middleware method to apply the auth middleware, this ensures that the user must be logged in to access this route.

Controller
-----------------------------------

.. code-block:: php
    :file: app/Http/Controller/HomeController.php 
    ...
    
    public function sales_agent_dashboard_table(Request $request)
    {
        $brand = GetCurrentBrandId();
        $commodity = $request->get('commodity');
        $channel = $request->get('channel');
        $start_date = $request->get('startDate') ?? Carbon::yesterday()->format('Y-m-d');
        $end_date = $request->get('endDate') ?? Carbon::today()->format('Y-m-d');
        $vendor = $request->get('vendor');
        $language = $request->get('language');
        $state = $request->get('state');
        $market = $request->get('market');
        $column = $request->get('column');
        $direction = $request->get('direction');

        $stats_product = StatsProduct::select(
            DB::raw("DATE_FORMAT(stats_product.event_created_at, '%Y-%m-%d') as day"),
            'stats_product.sales_agent_id',
            DB::raw('SUM(CASE WHEN result = "Sale" THEN 1 ELSE 0 END) AS sales'),
            DB::raw('SUM(CASE WHEN result = "No Sale" AND disposition_reason NOT IN ("Pending", "Abandoned") THEN 1 ELSE 0 END) AS no_sales'),
            'stats_product.sales_agent_name',
            'stats_product.vendor_name',
            'stats_product.office_name'
        )->eventRange(
            $start_date,
            $end_date
        )->where(
            'stats_product.brand_id',
            $brand
        )->whereNotNull('stats_product.sales_agent_id');

        if ($channel) {
            $stats_product = $stats_product->whereIn('channel_id', $channel);
        }

        if ($market) {
            $stats_product = $stats_product->whereIn('market_id', $market);
        }

        if ($language) {
            $stats_product = $stats_product->whereIn('language_id', $language);
        }

        if ($commodity) {
            $stats_product = $stats_product->whereIn('commodity_id', $commodity);
        }

        if ($state) {
            $stats_product = $stats_product->leftJoin(
                'states',
                'stats_product.service_state',
                'states.state_abbrev'
            )->whereIn(
                'states.id',
                $state
            );
        }

        if ('client' == session('portal')) {
            if ($vendor) {
                $stats_product = $stats_product->whereIn('vendor_id', $vendor);
            }
        } else {
            $stats_product = $stats_product->where('vendor_id', GetCurrentBrandId());
        }

        $stats_product = $stats_product->groupBy('stats_product.sales_agent_id', 'day');

        $stats_product = $stats_product->get()->toArray();
        //dd($stats_product);

        //Preparing the final result
        $agents_id = array_unique(array_column($stats_product, 'sales_agent_id'));
        $agents_name = [];
        $vendors = [];
        $offices = [];
        $selling_days = [];
        $sales_per_day = [];
        $sales = [];
        $no_sales = [];
        $efficiency = [];
        foreach ($agents_id as $agent) {
            foreach ($stats_product as $product) {
                if ($product['sales_agent_id'] == $agent) {
                    $selling_days[$agent] = isset($selling_days[$agent]) ? $selling_days[$agent] + 1 : 1;
                    $sales_per_day[$agent] = isset($sales_per_day[$agent]) ? $sales_per_day[$agent] + $product['sales'] : $product['sales'];
                    $no_sales[$agent] = isset($no_sales[$agent]) ? $no_sales[$agent] + $product['no_sales'] : $product['no_sales'];
                    $vendors[$agent] = $product['vendor_name'];
                    $offices[$agent] = $product['office_name'];
                    $agents_name[$agent] = $product['sales_agent_name'];
                }
            }
        }

        $avg_sales_per_day = [];
        foreach ($agents_id as $agent) {
            //I need to check values before to avoid division by zero error
            if (0 == $sales_per_day[$agent] or 0 == $selling_days[$agent]) {
                $avg_sales_per_day[$agent] = 0;
            } else {
                $avg_sales_per_day[$agent] = round($sales_per_day[$agent] / $selling_days[$agent], 2);
            }
            //Calculating the efficiency
            if (0 == $sales_per_day[$agent]) {
                $efficiency[$agent] = 0;
            } else {
                $efficiency[$agent] = round(
                    (100 * $sales_per_day[$agent] / ($sales_per_day[$agent] + $no_sales[$agent])),
                    2
                );
            }
        }

        $result = [];
        foreach ($agents_id as $agent) {
            $result[] = [
                'agents_id' => $agent,
                'agents_name' => $agents_name[$agent],
                'selling_days' => $selling_days[$agent],
                'sales_per_day' => $avg_sales_per_day[$agent],
                'efficiency' => $efficiency[$agent],
                'vendor_name' => $vendors[$agent],
                'office_name' => $offices[$agent],
            ];
        }
        //Sorting result y Sales per Day
        array_multisort(array_column($result, 'sales_per_day'), 3, $result);

        if ($column && $direction) {
            $sort_type = ('desc' == $direction) ? 3 : 4;
            array_multisort(array_column($result, $column), $sort_type, $result);
        }

        if ($request->get('csv')) {
            //Deleting unnecessary field agents_id from array
            foreach ($result as &$r) {
                unset($r['agents_id']);
            }

            return $this->csv_response(array_values($result), 'sales_dashboard_report');
        } else {
            return response()->json($result);
        }
    }
    ...    

Survey Dashboard
=================

.. code-block:: php
    :caption: routes of the dashboard `routes/web.php`
    
    use App\Models\ShortUri;
    ...
    use Illuminate\Support\Facades\Route;
    ...

    ...
    Route::get('dashboard/sales_agent_dashboard_table', 'HomeController@sales_agent_dashboard_table');
    Route::get('dashboard/survey_dashboard', 'SurveyDashboardController@survey_index');
    Route::get('dashboard/survey_dashboard/completed', 'SurveyDashboardController@completed');
    Route::get('dashboard/survey_dashboard/unsuccessful', 'SurveyDashboardController@unsuccessful');
    Route::get('dashboard/survey_dashboard/finalized', 'SurveyDashboardController@finalized');
    Route::get('dashboard/survey_dashboard/number_of_contacts', 'SurveyDashboardController@number_of_contacts');
    Route::get('dashboard/survey_dashboard/unsuccessful_dispositions', 'SurveyDashboardController@unsuccessful_dispositions');
    Route::get('dashboard/survey_dashboard/completed_unsuccessful_by_date', 'SurveyDashboardController@completed_unsuccessful_by_date');
    ...
