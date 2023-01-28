Main Dashboard
+++++++++++++++

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

Controller & Methods
------------------------

These are the ``controllers`` and ``methods`` responsible for the functionalities that is called in our ``routes``.

HomeController@index
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
This code is defining a method called index within the HomeController class in our Laravel application. The method is responsible for displaying the dashboard.

The first line of the method checks the value of the role_id session variable, if the session variable is equal to 3, it redirects the user to the sales_dashboard route.

The next lines of the method return a view called dashboard and pass an array of data to the view. The array contains several elements, such as vendors, languages, commodities, states and brands. Each element is set to the result of calling a method on the HomeController class, such as get_vendors(), get_languages(), get_commodities(), get_states() and get_brands().

These methods are likely to retrieve data from the database and return it to the view, so it can be displayed on the dashboard.

In summary, this code defines the index method, which is responsible for displaying the dashboard. If the user's role is set to 3 in the session, the user will be redirected to the sales dashboard, otherwise, the dashboard view is returned along with an array of data that is populated from the methods of the controller class.

.. literalinclude:: controllers/HomeController.php
    :language: php
    :caption: **HomeController@sales_agent_dashboard_table**
    :lines: 238-255
    :linenos: 

HomeController@sales_agent_dashboard_table
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

``app/Http/Controllers/HomeController.php``

This code is defining a method called sales_agent_dashboard_table within the HomeController class in a Laravel application. The method takes a Request object as an argument, which allows it to access data sent in the request such as query parameters.

The method starts by defining several variables such as brand, commodity, channel, start_date, end_date, vendor, language, state, market, column, direction. These variables are being set with the values from the request object using the get method.

Then, the method uses the StatsProduct model to retrieve data from the database. It uses the select method to specify which columns from the table should be retrieved. It also uses the DB::raw

The method then uses the eventRange method on the query to filter the data by a range of dates. Then, it uses the where method to filter the data by the brand_id and sales_agent_id column.

Then, it uses the whereIn method to filter the data by the channel_id, market_id, language_id, commodity_id and vendor_id columns if the corresponding values are present in the request. It also uses the leftJoin method to join the states table and filter by the state value if present in the request.

After that, the method uses the groupBy method to group the data by the sales_agent_id and day columns.

The method then retrieves the data by using the get method and converts it to an array.

Then, it loops through the data, creating several arrays like agents_id, agents_name, vendors, offices, selling_days, sales_per_day, sales, no_sales, efficiency.

The method doesn't return any value, but it generates an array with the desired data to be used in other parts of the application.

.. literalinclude:: controllers/HomeController.php
    :language: php
    :caption: **HomeController@sales_agent_dashboard_table**
    :lines: 2546-2690
    :linenos:

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
