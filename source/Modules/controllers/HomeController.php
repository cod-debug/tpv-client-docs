<?php

namespace App\Http\Controllers;

use function GuzzleHttp\json_decode;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Traits\SearchFormTrait;
use App\Traits\CSVResponseTrait;
use App\Models\Vendor;
use App\Models\User;
use App\Models\StatsProduct;
use App\Models\ShortUri;
use App\Models\Script;
use App\Models\Product;
use App\Models\Office;
use App\Models\Interaction;
use App\Models\EztpvConfig;
use App\Models\Dnis;
use App\Models\BrandUserOffice;
use App\Models\BrandUser;
use App\Models\BrandState;
use App\Models\BrandEztpvContract;
use App\Models\Brand;

/*
--- ADD A BRAND FOR DXC HISTORICAL CALL SEARCH ---
#1 - Add their GUID to 'private const BRAND_IDS'
#2 - Add a line in 'public function allow_dxc_call_search()' to allow that brand
#3 - Add the SQL View Name in 'private function getDxcView()'
#4 - In Microsoft SQL Server Management Studio (SSMS) create a VIEW on DXC4 for that brand
    4a - The view created in SSMS must match the string returned in getDxcView()
*/

class HomeController extends Controller
{
    use CSVResponseTrait;
    use SearchFormTrait;

    public function api_docs()
    {
        return view(
            'docs.api',
            [
                'base_url' => config('app.urls.clients'),
            ]
        );
    }

    public function nav_check(Request $request)
    {
        $eval_rbac = $request->get('rbac') ?? [];
        $rbac_response = [];
        if (count($eval_rbac) > 0) {
            foreach ($eval_rbac as $rb) {
                $rbac_response[$rb] = (\App\Helpers\Rbac::checkRole($rb)) ? true : false;
            }
        }

        $rbac_response['allow_dxc_call_search'] = $this->allow_dxc_call_search();

        if ($rbac_response['allow_dxc_call_search']){
            $rbac_response['dxc_view'] = $this->getDxcView();
        }

        return $rbac_response;
    }

    // BRAND_IDS - access with self::BRAND_IDS, expand as needed, list is for companies that came from DXC
    private const BRAND_IDS = [
        // DXC Companies
        'direct_energy'   => ['production' => '94d29d20-0bcf-49a3-a261-7b0c883cbd1d', 'staging' => '94d29d20-0bcf-49a3-a261-7b0c883cbd1d'],
        'green_mountain'  => ['production' => '7b0a45c2-a459-4810-9a51-b2c4c78c127e', 'staging' => '7b0a45c2-a459-4810-9a51-b2c4c78c127e'],
        'idt_energy'      => ['production' => '77c6df91-8384-45a5-8a17-3d6c67ed78bf', 'staging' => '77c6df91-8384-45a5-8a17-3d6c67ed78bf'],
        'nrg'             => ['production' => '7c8552f9-a40f-4952-8fcb-46ae5b0e9b1d', 'staging' => '7c8552f9-a40f-4952-8fcb-46ae5b0e9b1d'],
        'reliant_energy'  => ['production' => 'a56d5655-1de7-4aa5-9a76-bd2a2cda9e17', 'staging' => 'a56d5655-1de7-4aa5-9a76-bd2a2cda9e17'],
        'santanna_energy' => ['production' => 'a6271008-2dc4-4bac-b6df-aa55d8b79ec7', 'staging' => 'a6271008-2dc4-4bac-b6df-aa55d8b79ec7'],
        'spark_energy'    => ['production' => 'c72feb62-44e7-4c46-9cda-a04bd0c58275', 'staging' => 'c72feb62-44e7-4c46-9cda-a04bd0c58275'],
        'txu_energy'      => ['production' => '200979d8-e0f5-41fb-8aed-e58a91292ca0', 'staging' => '200979d8-e0f5-41fb-8aed-e58a91292ca0'],
    ];

    /** isCompanyBrandId(string $company_string) - Returns true if $this->brand_id is for $company_name string 
     * Example : $this->isCompanyBrandId('direct_energy') and $this->brand_id is '94d29d20-0bcf-49a3-a261-7b0c883cbd1d'
     */
    private function isCompanyBrandId(string $company_string) : bool {
        $brand_id = request()->session()->get('works_for_id');

        // If the BRAND_IDS array doesnt have an Array Key (IE self::BRAND_IDS['foo']) doesn't exist, return false
        if (!array_key_exists($company_string, self::BRAND_IDS )) return false;

        // If the Brand ID is found (IE self::BRAND_IDS['my_company']['prod' => 'some_guid','stag'=>'a_guid']) for the Company, return true
        if (in_array($brand_id, self::BRAND_IDS[$company_string])) return true;

        // Required due to bool return type
        return false;
    }    

    /** allow_dxc_call_search() 
     * - Only a few brands need to have access to search the MS-SQL (not MySQL) DXC Calls Database for records
     * - Returns true if the brand_id is set up for searching.
     * - If needed, we can add other restrictions so only some people / roles can search for call recordings.
    */
    public function allow_dxc_call_search() : ?string
    {
        // DO NOT REMOVE - Restrict DXC Search Sidebar Icon to Client Admins ONLY (this includes Developers)
        if (request()->session()->get('portal') != 'client') return false;

        $brand_id = request()->session()->get('works_for_id');

        // Add other conditions and return false here if needed

        // NOTE: There is no focus for Intelligent Energy or Volunteer Energy

        if (
            in_array($brand_id, self::BRAND_IDS['direct_energy']) || 
            in_array($brand_id, self::BRAND_IDS['green_mountain']) || 
            in_array($brand_id, self::BRAND_IDS['idt_energy']) || 
            in_array($brand_id, self::BRAND_IDS['nrg']) || 
            in_array($brand_id, self::BRAND_IDS['reliant_energy']) || 
            in_array($brand_id, self::BRAND_IDS['santanna_energy']) || 
            in_array($brand_id, self::BRAND_IDS['spark_energy']) || 
            in_array($brand_id, self::BRAND_IDS['txu_energy'])
        ) {
            // true to display Call Search button on Clients Sidebar
            return true;
        }

        return false;
    }    

    private function getDxcView() : ?string {
        if      ($this->isCompanyBrandId('direct_energy'))            return 'VIEW_Activewav_Direct_Energy';
        else if ($this->isCompanyBrandId('green_mountain'))           return 'VIEW_Activewav_Green_Mountain';
        else if ($this->isCompanyBrandId('idt_energy'))               return 'VIEW_Activewav_IDT_Energy';
        else if ($this->isCompanyBrandId('reliant_energy'))           return 'VIEW_Activewav_Reliant_Energy';
        else if ($this->isCompanyBrandId('nrg'))                      return 'VIEW_Activewav_NRG';
        else if ($this->isCompanyBrandId('santanna_energy'))          return 'VIEW_Activewav_Santanna_Energy';
        else if ($this->isCompanyBrandId('spark_energy'))             return 'VIEW_Activewav_Spark_Energy';
        else if ($this->isCompanyBrandId('txu_energy'))               return 'VIEW_Activewav_TXU_Energy';

        return null;
    }

    public function short_uri($key)
    {
        if (strpos($key, ' ') !== false) {
            $keyArray = explode(' ', $key, 2);
            if (!empty($keyArray)) {
                $key = $keyArray[0];
            }
        }

        $u = ShortUri::where('key', $key)->first();
        if ($u) {
            return redirect($u->destination_uri);
        }
        abort(404);
    }

    public function get_home_destination()
    {

        if (!Auth::check()) {
            return redirect('/login');
        }

        if (!session()->exists('current_brand') || !session()->exists('user.permissions')) {
            return redirect('/chooser');
        }

        return redirect('/dashboard');
    }

    public function version()
    {
        $loggedIn = Auth::check();
        $bu = null;
        if ($loggedIn) {
            if (session('portal') == 'vendor') {
                $bu = BrandUser::where('user_id', Auth::id())->where('works_for_id', session('works_for_id'))->first();
            } else {
                $bu = BrandUser::where('user_id', Auth::id())->where('works_for_id', GetCurrentBrandId())->first();
            }
        }
        $authed = $loggedIn && (
            ($bu !== null && $bu->status == 1)
            || session('staff_login') === true);

        $flash_message = null;

        return response()->json([
            'auth' => $loggedIn,
            'version' => config('app.version'),
            //'session' => session()->all(),
            'authed' => $authed,
            'message' => $flash_message,
            //'bu' => $bu,
            //'id' => Auth::id(),
        ]);
    }

    public function get_filled_route()
    {
        $r = request()->input('name');
        $p = request()->input('params');
        if ($p !== null) {
            $p = json_decode($p, true);
        }
        try {
            return response()->json([
                'error' => false,
                'route' => $p !== null ? route($r, $p, true) : route($r, [], true),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => true,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function get_user(Request $request)
    {
        return $request->user();
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // show sales dashboard instead of usual dashboard for sales agents
        if (session('role_id') === 3) {
            return redirect()->route('sales_dashboard');
        }

        return view(
            'dashboard',
            [
                'vendors' => $this->get_vendors(),
                'languages' => $this->get_languages(),
                'commodities' => $this->get_commodities(),
                'states' => $this->get_states(),
                'brands' => $this->get_brands(),
            ]
        );
    }

    public function salesAgentDnis(Request $request)
    {
        $number = null;
        $show_number = false;
        $office = $this->current_office(true);
        if ($office) {
            // This will only work with eztpv config version 2
            $office_config = EztpvConfig::where(
                'office_id',
                $office->id
            )->first();
            if ($office_config) {
                if (is_string($office_config->config)) {
                    $office_config->config = json_decode($office_config->config, true);
                }

                if ($request->state && $request->channel) {
                    $oc = $office_config->toArray();
                    if (isset($oc['config'][$request->state]['channels'][$request->channel]['script'])) {
                        $dnis = Dnis::select(
                            'dnis.dnis'
                        )->leftJoin(
                            'scripts',
                            'dnis.id',
                            'scripts.dnis_id'
                        )->where(
                            'scripts.id',
                            $oc['config'][$request->state]['channels'][$request->channel]['script']
                        )->first();
                        if ($dnis) {
                            $show_number = true;
                            $number = $dnis->dnis;
                        }
                    }
                } else {
                    foreach ($office_config->config as $state) {
                        if ($state['status'] === 'on' && isset($state['status'])) {
                            foreach ($state['channels'] as $channel) {
                                if (isset($channel['script'])) {
                                    $dnis = Dnis::select(
                                        'dnis.dnis'
                                    )->leftJoin(
                                        'scripts',
                                        'dnis.id',
                                        'scripts.dnis_id'
                                    )->where(
                                        'scripts.id',
                                        $channel['script']
                                    )->first();
                                    if ($dnis) {
                                        $numbers[] = $dnis->dnis;
                                    }
                                }
                            }
                        }
                    }

                    $numbers = array_unique($numbers);

                    if (count($numbers) == 1) {
                        $show_number = true;
                        $number = $numbers[0];
                    }
                }
            }
        }

        return response()->json(
            [
                'show_number' => $show_number,
                'number' => $number,
            ]
        );
    }

    /**
     * Show the sales dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function salesDashboard()
    {
        return view(
            'sales_dashboard'
        );
    }

    public function liveCall()
    {
        return view(
            'sales_agents.live_call'
        );
    }

    public function scriptToDnis(Request $request)
    {
        $dnis = Script::select(
            'dnis.dnis'
        )->leftJoin(
            'dnis',
            'scripts.dnis_id',
            'dnis.id'
        )->where(
            'scripts.id',
            $request->script
        )->first();
        if ($dnis) {
            return $dnis;
        }

        return null;
    }

    public function canLiveCall(Request $request)
    {
        $brand_state = BrandState::select(
            'brand_states.eztpv_only'
        )->where(
            'brand_states.brand_id',
            session('works_for_id')
        )->where(
            'brand_states.state_id',
            $request->state
        )->first();
        if ($brand_state) {
            return $brand_state;
        }

        return null;
    }

    public function list_salesDashboard(Request $request)
    {
        $search = $request->get('search');
        $start_date = $request->get('startDate') ?? Carbon::now()->format('Y-m-d');
        $end_date = $request->get('endDate') ?? Carbon::now()->format('Y-m-d');
        $column = $request->get('column') ?? 'event_created_at';
        $direction = $request->get('direction') ?? 'desc';
        //Get current page form url e.g. &page=6
        $currentPage = LengthAwarePaginator::resolveCurrentPage();

        $getResults = function () use ($request, $search, $start_date, $end_date, $column, $direction, $currentPage) {
            $sps = StatsProduct::select(
                'stats_product.event_id',
                'stats_product.eztpv_id',
                'stats_product.eztpv_initiated',
                'stats_product.event_created_at',
                'stats_product.auth_first_name',
                'stats_product.auth_last_name',
                'stats_product.confirmation_code',
                'stats_product.dnis',
                'stats_product.result',
                'stats_product.disposition_reason',
                'stats_product.office_id',
                'stats_product.service_state',
                'stats_product.channel_id',
                'eztpvs.finished'
            )->join(
                'brand_users',
                'stats_product.sales_agent_id',
                'brand_users.id'
            )->join(
                'users',
                'brand_users.user_id',
                'users.id'
            )->leftJoin(
                'eztpvs',
                'stats_product.eztpv_id',
                'eztpvs.id'
            )->where(
                'users.id',
                Auth::id()
            )->eventRange(
                $start_date,
                $end_date
            )->where(
                'stats_product.stats_product_type_id',
                1
            );

            if (null != $search) {
                $sps = $sps->interactionSearch($search);
            }

            if ($column && $direction) {
                $sps = $sps->orderBy($column, $direction);
            }

            $sps = $sps->groupBy(
                'stats_product.confirmation_code'
            )->paginate(25);

            return $sps;
        };

        if ($search != null || $currentPage > 1) {
            $data = $getResults();
        } else {
            $data = Cache::remember(Auth::id() . '-' . $start_date . $end_date . $column . $direction . '-salesDashboard', 30, $getResults);
        }

        return response()->json($data);
    }

    /**
     * Brand Chooser.
     *
     * @param Request $request - request object
     */
    public function chooser(Request $request)
    {
        // If the user only has 1 brand assigned, send them straight to the dashboard
        if (1 == count(Auth::user()->brands)) {

            return $this->choose_brand(
                $request,
                Auth::user()->brands[0]->works_for_id,
                Auth::user()->brands[0]->employee_of_id,
                Auth::user()->brands[0]->role_id
            );
        } else {
            //return view('chooser')->with(['no_multiple_check' => true]);
            $brands = session('avail_brands');
            $brands = $brands->map(function ($item) {
                $item->works_for_e = Brand::select(
                    'brands.id',
                    'brands.created_at',
                    'brands.updated_at',
                    'brands.client_id',
                    'brands.name',
                    'brands.address',
                    'brands.city',
                    'brands.state',
                    'brands.zip',
                    'brands.service_number',
                    'brands.logo_path',
                    'brands.active',
                    'uploads.filename'
                )->leftJoin(
                    'uploads',
                    'brands.logo_path',
                    'uploads.id'
                )->where(
                    'brands.id',
                    $item->works_for_id
                )->first();
                return $item;
            });
            return view(
                'generic-vue-no-sidebar',
                [
                    'componentName' => 'multi-brand-chooser',
                    'title' => 'Brand Chooser',
                    'parameters' => [
                        'no-multiple-check' => true,
                        'available-brands' => json_encode($brands),
                        'user' => json_encode(Auth::user()),
                        'cloudfront' => json_encode(config('services.aws.cloudfront.domain')),
                    ],
                ]
            )->with([
                'noCheck' => true
            ]);
        }
    }

    /**
     * Staff Login.
     *
     * @param Request $request - request object
     */
    public function staff(Request $request)
    {
        if (1 == $request->loginAs) {
            $user = User::find($request->id);

            if ($user) {
                Cache::forget('user_perms_' . $user->id);
                session()->flush();
                Auth::logout();
                if ($user->staff_token == $request->token) {
                    Log::debug('Logging in as ' . json_encode($user->toArray()));

                    $bu = BrandUser::where('user_id', $user->id)->first();
                    // Only check office/vendor enabled for sales agents
                    if ($bu && (2 == $bu->role_id || 3 == $bu->role_id)) {
                        // Check Vendor
                        $v = Vendor::where(
                            'vendor_id',
                            $bu->employee_of_id
                        )->where(
                            'brand_id',
                            $bu->works_for_id
                        )->first();
                        if (!$v) {
                            Auth::logout();

                            return view(
                                'auth.vendor_locked'
                            )->with(['user' => $user]);
                        }

                        // Check office
                        $o = BrandUserOffice::select(
                            'brand_user_offices.id'
                        )->where(
                            'brand_user_id',
                            $bu->id
                        )->join(
                            'offices',
                            'brand_user_offices.office_id',
                            'offices.id'
                        )->first();
                        if (!$o) {
                            Auth::logout();

                            return view(
                                'auth.office_locked'
                            )->with(['user' => $user]);
                        }
                    }

                    if (null === $bu || 0 == $bu->status) {
                        Auth::logout();

                        return view(
                            'auth.locked'
                        )->with(['user' => $user]);
                    }

                    if ($bu) {
                        Auth::login($user);

                        $brand = Brand::select(
                            'brands.id',
                            'brands.created_at',
                            'brands.updated_at',
                            'brands.client_id',
                            'brands.name',
                            'brands.address',
                            'brands.city',
                            'brands.state',
                            'brands.zip',
                            'brands.service_number',
                            'brands.logo_path',
                            'brands.active',
                            'uploads.filename'
                        )->leftJoin(
                            'uploads',
                            'brands.logo_path',
                            'uploads.id'
                        )->where(
                            'brands.id',
                            $bu->employee_of_id
                        )->first();

                        info('Brand is ' . json_encode($brand));

                        if (null == $brand) {
                            abort(400);
                        }

                        $request->session()->put('role_id', $bu->role_id);
                        $request->session()->put(
                            'employee_of_id',
                            $bu->employee_of_id
                        );
                        $request->session()->put('works_for_id', $bu->works_for_id);
                        $request->session()->put('current_brand', $brand);

                        if ($brand->client_id) {
                            $request->session()->put('portal', 'client');
                        } else {
                            $request->session()->put('portal', 'vendor');
                        }

                        $perms = Cache::rememberForever(
                            'perms_for_user_' . $bu->id,
                            function () use ($bu) {
                                return get_perms($bu->role_id, Auth::user()->id);
                            }
                        );

                        $request->session()->put('user.permissions', $perms);

                        $request->session()->save();

                        $timeTok = dechex(time());

                        if ($user) {
                            $user->api_token = 'U' . substr(hash('sha256', "{$user->username}:{$user->password}:{$timeTok}") . ':' . $timeTok, 1);
                            $user->staff_token = null;
                            $user->save();
                        }

                        if ($request->dest) {
                            info('Redirecting to ' . $request->dest);
                            return redirect($request->dest);
                        }

                        info('Redirecting to /dashboard.');
                        return Redirect::to('/dashboard');
                    }
                } else {
                    echo 'Token mismatch.';
                }
            } else {
                abort(404);
            }
        } else {
            $user = User::select(
                'id',
                'first_name',
                'last_name',
                'username',
                'password',
                'remember_token',
                'staff_token',
                'avatar'
            )->where('id', $request->login)->first();

            info('User is ' . json_encode($user));

            if ($user) {
                Cache::forget('user_perms_' . $user->id);
                session()->flush();
                Auth::logout();

                info('Staff token is ' . $user->staff_token);
                info('Request token is ' . $request->token);

                if ($user->staff_token == $request->token) {
                    info('Trying to login...');

                    Auth::login($user);

                    $brand = Brand::select(
                        'brands.id',
                        'brands.created_at',
                        'brands.updated_at',
                        'brands.client_id',
                        'brands.name',
                        'brands.address',
                        'brands.city',
                        'brands.state',
                        'brands.zip',
                        'brands.service_number',
                        'brands.logo_path',
                        'brands.active',
                        'uploads.filename'
                    )->leftJoin(
                        'uploads',
                        'brands.logo_path',
                        'uploads.id'
                    )->where(
                        'brands.id',
                        $request->brand_id
                    )->first();

                    info('Brand is ' . json_encode($brand));

                    if (null == $brand) {
                        abort(400);
                    }

                    $request->session()->put('staff_login', true);
                    $request->session()->put('role_id', 1);
                    $request->session()->put('employee_of_id', $request->brand_id);
                    $request->session()->put('works_for_id', $request->brand_id);
                    $request->session()->put('current_brand', $brand);

                    if ($request->vendor) {
                        $request->session()->put('portal', 'vendor');
                    } else {
                        $request->session()->put('portal', 'client');
                    }

                    $request->session()->put('user.permissions', ['*']);

                    $request->session()->save();

                    $timeTok = dechex(time());

                    if ($user) {
                        $user->api_token = 'U' . substr(hash('sha256', "{$user->username}:{$user->password}:{$timeTok}") . ':' . $timeTok, 1);
                        $user->staff_token = null;
                        $user->save();
                    }

                    if ($request->dest) {
                        info('Redirecting to ' . $request->dest);
                        return redirect($request->dest);
                    }

                    info('Redirecting to /dashboard.');

                    return Redirect::to('/dashboard');
                } else {
                    echo 'Token mismatch.';
                }
            } else {
                abort(404);
            }
        }
    }

    /**
     * Choose a brand.
     *
     * @param Request $request  - string
     * @param string  $brand_id - brand id
     */
    public function choose_brand(Request $request, $brand_id, $vendor_id, $role_id = null)
    {
        if (!isset($role_id)) {
            $role_id = request()->input('role_id');
        }

        $brand = Brand::select(
            'brands.id',
            'brands.created_at',
            'brands.updated_at',
            'brands.client_id',
            'brands.name',
            'brands.name',
            'brands.address',
            'brands.city',
            'brands.state',
            'brands.zip',
            'brands.service_number',
            'brands.logo_path',
            'brands.active',
            'uploads.filename'
        )->leftJoin(
            'uploads',
            'brands.logo_path',
            'uploads.id'
        )->find($brand_id);
        if (null == $brand) {
            abort(400);
        }

        $bu = BrandUser::select(
            'id',
            'created_at',
            'updated_at',
            'employee_of_id',
            'works_for_id',
            'user_id',
            'tsr_id',
            'role_id',
            'status'
        )->where(
            'works_for_id',
            $brand->id
        )->where(
            'employee_of_id',
            $vendor_id
        )->where(
            'user_id',
            Auth::user()->id
        )->where(
            'role_id',
            $role_id
        )->first();

        if (null == $bu) {
            abort(400);
        }

        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($bu, $brand) {
            $scope->setUser([
                'id' => Auth::id(),
                'email' => $bu->id . '@' . Auth::id(),
            ]);

            $scope->setExtra('user.current_brand', $brand->id);
            $scope->setExtra('user.role_id', $bu->role_id);
        });

        $request->session()->put('role_id', $bu->role_id);
        $request->session()->put('employee_of_id', $bu->employee_of_id);
        $request->session()->put('works_for_id', $bu->works_for_id);
        $request->session()->put('current_brand', $brand);
        $request->session()->save();

        $perms = Cache::rememberForever(
            'perms_for_user_' . $bu->id,
            function () use ($bu) {
                return get_perms($bu->role_id, Auth::user()->id);
            }
        );

        $request->session()->put('user.permissions', $perms);

        if ($request->session()->has('redirect-to')) {
            $redirTo = $request->session()->get('redirect-to');
            $request->session()->forget('redirect-to');

            return redirect($redirTo);
        }

        return redirect('/dashboard');
    }

    /**
     * List to Array.
     *
     * @param array $items - array of items
     */
    private function listToArray($items)
    {
        if (is_array($items)) {
            return $items;
        }

        if (null != $items && strlen(trim($items)) > 0) {
            return explode(',', $items);
        }

        return [];
    }

    public function sales_no_sales(Request $request)
    {
        $params = $this->reportParams($request);
        $data = $this->_sales_no_sales(...$params);

        return response()->json($data);
    }

    private function _sales_no_sales(
        $brand_id,
        $start_date,
        $end_date,
        $channel,
        $market,
        $vendor,
        $brand,
        $language,
        $commodity,
        $state
    ) {
        $data = StatsProduct::select(
            DB::raw('SUM(CASE WHEN result = "Sale" THEN 1 ELSE 0 END) AS sales'),
            DB::raw('SUM(CASE WHEN result = "No Sale" AND disposition_reason NOT IN ("Pending", "Abandoned") THEN 1 ELSE 0 END) AS nosales')
        )->eventRange(
            $start_date,
            $end_date
        );

        if ('client' == session('portal')) {
            $data = $data->where('brand_id', $brand_id);

            if ($vendor) {
                $data = $data->whereIn('vendor_id', $this->listToArray($vendor));
            }
        } else {
            if (2 == session('role_id')) {
                $data = $data->where('office_id', $this->current_office()->id);
            } else {
                $data = $data->where('vendor_id', $brand_id);
            }

            if ($brand) {
                $data = $data->whereIn('brand_id', $this->listToArray($brand));
            }
        }

        if ($channel) {
            $data = $data->whereIn('channel_id', $this->listToArray($channel));
        }

        if ($market) {
            $data = $data->whereIn('market_id', $this->listToArray($market));
        }

        if ($language) {
            $data = $data->whereIn('language_id', $this->listToArray($language));
        }

        if ($commodity) {
            $data = $data->whereIn('commodity_id', $this->listToArray($commodity));
        }

        if ($state) {
            $data = $data->leftJoin(
                'states',
                'stats_product.service_state',
                'states.state_abbrev'
            )->whereIn(
                'states.id',
                $this->listToArray($state)
            );
        }

        $data = $data->get();

        if (isset($data[0])) {
            $data = $data[0];
            $data->total = $data['sales'] + $data['nosales'];
            $data->sale_percentage = (isset($data['sales'])
                && $data['sales'] > 0
                && $data->total > 0) ? number_format(
                ($data['sales'] / $data->total) * 100,
                2
            ) : 0;
            $data->no_sale_percentage = (isset($data['nosales'])
                && $data['nosales'] > 0
                && $data->total > 0) ? number_format(
                ($data['nosales'] / $data->total) * 100,
                2
            ) : 0;
        } else {
            $data = null;
        }

        return $data;
    }

    public function sales_no_sales_dataset(Request $request)
    {
        $params = $this->reportParams($request);
        $data = $this->_sales_no_sales_dataset(...$params);

        return response()->json($data);
    }

    private function _sales_no_sales_dataset(
        $brand_id,
        $start_date,
        $end_date,
        $channel,
        $market,
        $vendor,
        $brand,
        $language,
        $commodity,
        $state
    ) {
        if ($start_date == $end_date) {
            $data = StatsProduct::select(
                DB::raw('HOUR(event_created_at) AS the_dates'),
                DB::raw('SUM(CASE WHEN result = "Sale" THEN 1 ELSE 0 END) AS sales'),
                DB::raw('SUM(CASE WHEN result = "No Sale" AND disposition_reason NOT IN ("Pending", "Abandoned") THEN 1 ELSE 0 END) AS nosales')
            )->eventRange(
                $start_date,
                $end_date
            );

            if ('client' == session('portal')) {
                $data = $data->where('brand_id', $brand_id);

                if ($vendor) {
                    $data = $data->whereIn('vendor_id', $this->listToArray($vendor));
                }
            } else {
                if (2 == session('role_id')) {
                    $data = $data->where('office_id', $this->current_office()->id);
                } else {
                    $data = $data->where('vendor_id', $brand_id);
                }

                if ($brand) {
                    $data = $data->whereIn('brand_id', $this->listToArray($brand));
                }
            }

            if ($channel) {
                $data = $data->whereIn('channel_id', $this->listToArray($channel));
            }

            if ($market) {
                $data = $data->whereIn('market_id', $this->listToArray($market));
            }

            if ($language) {
                $data = $data->whereIn('language_id', $this->listToArray($language));
            }

            if ($commodity) {
                $data = $data->whereIn(
                    'commodity_id',
                    $this->listToArray($commodity)
                );
            }

            if ($state) {
                $data = $data->leftJoin(
                    'states',
                    'stats_product.service_state',
                    'states.state_abbrev'
                )->whereIn(
                    'states.id',
                    $this->listToArray($state)
                );
            }

            $data = $data->groupBy(
                DB::raw('HOUR(event_created_at)')
            )->get()->toArray();

            $labels = [7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23];

            $newarray = [];
            for ($i = 0; $i < count($data); ++$i) {
                $newarray[$data[$i]['the_dates']] = $data[$i];
            }

            for ($i = 7; $i < 24; ++$i) {
                if (!isset($newarray[$i])) {
                    $newarray[$i] = [
                        'the_dates' => $i,
                        'sales' => 0,
                        'nosales' => 0,
                    ];
                }
            }

            asort($newarray);

            $sales = [];
            $nosales = [];

            foreach ($newarray as $na) {
                $sales[] = intval($na['sales']);
                $nosales[] = intval($na['nosales']);
            }
        } else {
            $data = StatsProduct::select(
                DB::raw('DATE_FORMAT(stats_product.event_created_at, "%Y-%m-%d") AS the_dates'),
                DB::raw('SUM(CASE WHEN stats_product.result = "Sale" THEN 1 ELSE 0 END) AS sales'),
                DB::raw('SUM(CASE WHEN stats_product.result = "No Sale" AND disposition_reason NOT IN ("Pending", "Abandoned") THEN 1 ELSE 0 END) AS nosales')
            )->eventRange(
                $start_date,
                $end_date
            );

            if ('client' == session('portal')) {
                $data = $data->where(
                    'stats_product.brand_id',
                    $brand_id
                );

                if ($vendor) {
                    $data = $data->whereIn(
                        'stats_product.vendor_id',
                        $this->listToArray($vendor)
                    );
                }
            } else {
                if (2 == session('role_id')) {
                    $data = $data->where(
                        'stats_product.office_id',
                        $this->current_office()->id
                    );
                } else {
                    $data = $data->where('stats_product.vendor_id', $brand_id);
                }

                if ($brand) {
                    $data = $data->whereIn(
                        'stats_product.brand_id',
                        $this->listToArray($brand)
                    );
                }
            }

            if ($channel) {
                $data = $data->whereIn(
                    'stats_product.channel_id',
                    $this->listToArray($channel)
                );
            }

            if ($market) {
                $data = $data->whereIn(
                    'stats_product.market_id',
                    $this->listToArray($market)
                );
            }

            if ($language) {
                $data = $data->whereIn(
                    'stats_product.language_id',
                    $this->listToArray($language)
                );
            }

            if ($commodity) {
                $data = $data->whereIn(
                    'stats_product.commodity_id',
                    $this->listToArray($commodity)
                );
            }

            if ($state) {
                $data = $data->leftJoin(
                    'states',
                    'stats_product.service_state',
                    'states.state_abbrev'
                )->whereIn(
                    'states.id',
                    $this->listToArray($state)
                );
            }

            $data = $data->groupBy(
                DB::raw('DATE_FORMAT(event_created_at, "%Y-%m-%d")')
            )->get()->toArray();

            $labels = [];
            $period = \Carbon\CarbonPeriod::create($start_date, $end_date);
            foreach ($period as $date) {
                $labels[] = $date->format('Y-m-d');
            }

            $newarray = [];
            for ($i = 0; $i < count($data); ++$i) {
                $newarray[$data[$i]['the_dates']] = $data[$i];
            }

            foreach ($labels as $label) {
                if (!isset($newarray[$label])) {
                    $newarray[$label] = [
                        'the_dates' => $label,
                        'sales' => 0,
                        'nosales' => 0,
                    ];
                }
            }

            asort($newarray);

            $sales = [];
            $nosales = [];

            foreach ($newarray as $na) {
                $sales[] = intval($na['sales']);
                $nosales[] = intval($na['nosales']);
            }
        }

        return [
            'labels' => $labels,
            'nosales' => $nosales,
            'sales' => $sales,
        ];
    }

    public function top_sale_agents(Request $request)
    {
        $params = $this->reportParams($request);
        $data = $this->_top_sale_agents(...$params);

        return response()->json($data);
    }

    private function _top_sale_agents(
        $brand_id,
        $start_date,
        $end_date,
        $channel,
        $market,
        $vendor,
        $brand,
        $language,
        $commodity,
        $state
    ) {
        $data = StatsProduct::select(
            'sales_agent_name AS sales_agent',
            'vendor_name AS vendor',
            'brand_name AS brand',
            DB::raw('SUM(CASE WHEN result = "Sale" THEN 1 ELSE 0 END) AS sales'),
            DB::raw('SUM(CASE WHEN result = "No Sale" AND disposition_reason NOT IN ("Pending", "Abandoned") THEN 1 ELSE 0 END) AS nosales')
        )->whereNotNull(
            'sales_agent_name'
        )->whereNotNull(
            'vendor_name'
        )->where(
            'result',
            '!=',
            'Closed'
        )->eventRange(
            $start_date,
            $end_date
        );

        if ('client' == session('portal')) {
            $data = $data->where('brand_id', $brand_id);

            if ($vendor) {
                $data = $data->whereIn('vendor_id', $this->listToArray($vendor));
            }
        } else {
            if (2 == session('role_id')) {
                $data = $data->where('office_id', $this->current_office()->id);
            } else {
                $data = $data->where('vendor_id', $brand_id);
            }

            if ($brand) {
                $data = $data->whereIn('brand_id', $this->listToArray($brand));
            }
        }

        if ($channel) {
            $data = $data->whereIn('channel_id', $this->listToArray($channel));
        }

        if ($market) {
            $data = $data->whereIn('market_id', $this->listToArray($market));
        }

        if ($language) {
            $data = $data->whereIn('language_id', $this->listToArray($language));
        }

        if ($commodity) {
            $data = $data->whereIn('commodity_id', $this->listToArray($commodity));
        }

        if ($state) {
            $data = $data->leftJoin(
                'states',
                'stats_product.service_state',
                'states.state_abbrev'
            )->whereIn(
                'states.id',
                $this->listToArray($state)
            );
        }


        if ('vendor' === session('portal')) {
            $data = $data->groupBy(
                'sales_agent',
                'brand'
            );
        } else {
            $data = $data->groupBy(
                'sales_agent',
                'vendor'
            );
        }

        return $data->orderBy(
            'sales',
            'desc'
        )->limit(10)
            ->get();
    }

    public function top_sold_products(Request $request)
    {
        $params = $this->reportParams($request);
        $data = $this->_top_sold_products(...$params);

        return response()->json($data);
    }

    private function _top_sold_products(
        $brand_id,
        $start_date,
        $end_date,
        $channel,
        $market,
        $vendor,
        $brand,
        $language,
        $commodity,
        $state
    ) {
        $data = StatsProduct::select(
            'product_name AS name',
            DB::raw('SUM(CASE WHEN result = "Sale" THEN 1 ELSE 0 END) AS sales_num')
        )->whereNotNull(
            'product_name'
        )->eventRange(
            $start_date,
            $end_date
        );

        if ('client' == session('portal')) {
            $data = $data->where('brand_id', $brand_id);

            if ($vendor) {
                $data = $data->whereIn('vendor_id', $this->listToArray($vendor));
            }
        } else {
            if (2 == session('role_id')) {
                $data = $data->where('office_id', $this->current_office()->id);
            } else {
                $data = $data->where('vendor_id', $brand_id);
            }

            if ($brand) {
                $data = $data->whereIn('brand_id', $this->listToArray($brand));
            }
        }

        if ($channel) {
            $data = $data->whereIn('channel_id', $this->listToArray($channel));
        }

        if ($market) {
            $data = $data->whereIn('market_id', $this->listToArray($market));
        }

        if ($language) {
            $data = $data->whereIn('language_id', $this->listToArray($language));
        }

        if ($commodity) {
            $data = $data->whereIn('commodity_id', $this->listToArray($commodity));
        }

        if ($state) {
            $data = $data->leftJoin(
                'states',
                'stats_product.service_state',
                'states.state_abbrev'
            )->whereIn(
                'states.id',
                $this->listToArray($state)
            );
        }

        $data = $data->groupBy(
            'name'
        )->orderBy(
            'sales_num',
            'desc'
        )->limit(10)->get();

        return $data;
    }

    public function no_sale_dispositions(Request $request)
    {
        $params = $this->reportParams($request);
        $data = $this->_no_sale_dispositions(...$params);

        return response()->json($data);
    }

    private function _no_sale_dispositions(
        $brand_id,
        $start_date,
        $end_date,
        $channel,
        $market,
        $vendor,
        $brand,
        $language,
        $commodity,
        $state
    ) {
        $data = StatsProduct::select(
            'disposition_reason AS reason',
            DB::raw('COUNT(*) AS no_sales_num')
        )->whereNotIn(
            'disposition_reason',
            ['Abandoned', 'Pending']
        )->eventRange(
            $start_date,
            $end_date
        );

        if ('client' == session('portal')) {
            $data = $data->where('brand_id', $brand_id);

            if ($vendor) {
                $data = $data->whereIn('vendor_id', $this->listToArray($vendor));
            }
        } else {
            if (2 == session('role_id')) {
                $data = $data->where('office_id', $this->current_office()->id);
            } else {
                $data = $data->where('vendor_id', $brand_id);
            }

            if ($brand) {
                $data = $data->whereIn('brand_id', $this->listToArray($brand));
            }
        }

        if ($channel) {
            $data = $data->whereIn('channel_id', $this->listToArray($channel));
        }

        if ($market) {
            $data = $data->whereIn('market_id', $this->listToArray($market));
        }

        if ($language) {
            $data = $data->whereIn('language_id', $this->listToArray($language));
        }

        if ($commodity) {
            $data = $data->whereIn('commodity_id', $this->listToArray($commodity));
        }

        if ($state) {
            $data = $data->leftJoin(
                'states',
                'stats_product.service_state',
                'states.state_abbrev'
            )->whereIn(
                'states.id',
                $this->listToArray($state)
            );
        }

        return $data->groupBy(
            'reason'
        )->orderBy(
            'no_sales_num',
            'desc'
        )->get();
    }

    public function sales_by_vendor(Request $request)
    {
        $params = $this->reportParams($request);
        $data = $this->_sales_by_vendor(...$params);

        return response()->json($data);
    }

    private function _sales_by_vendor(
        $brand_id,
        $start_date,
        $end_date,
        $channel,
        $market,
        $vendor,
        $brand,
        $language,
        $commodity,
        $state
    ) {
        $field = 'client' == session('portal') ? 'vendor' : 'brand';
        $data = StatsProduct::select(
            $field . '_name AS name',
            DB::raw('SUM(CASE WHEN result = "Sale" THEN 1 ELSE 0 END) AS sales_num'),
            DB::raw('SUM(CASE WHEN result = "No Sale" AND disposition_reason NOT IN ("Pending", "Abandoned") THEN 1 ELSE 0 END) AS nosales_num')
        )->whereNotNull(
            $field . '_name'
        )->eventRange(
            $start_date,
            $end_date
        );

        if ('client' == session('portal')) {
            $data = $data->where('brand_id', $brand_id);

            if ($vendor) {
                $data = $data->whereIn('vendor_id', $this->listToArray($vendor));
            }
        } else {
            if (2 == session('role_id')) {
                $data = $data->where('office_id', $this->current_office()->id);
            } else {
                $data = $data->where('vendor_id', $brand_id);
            }

            if ($brand) {
                $data = $data->whereIn('brand_id', $this->listToArray($brand));
            }
        }

        if ($channel) {
            $data = $data->whereIn('channel_id', $this->listToArray($channel));
        }

        if ($market) {
            $data = $data->whereIn('market_id', $this->listToArray($market));
        }

        if ($language) {
            $data = $data->whereIn('language_id', $this->listToArray($language));
        }

        if ($commodity) {
            $data = $data->whereIn('commodity_id', $this->listToArray($commodity));
        }

        if ($state) {
            $data = $data->leftJoin(
                'states',
                'stats_product.service_state',
                'states.state_abbrev'
            )->whereIn(
                'states.id',
                $this->listToArray($state)
            );
        }

        $data = $data->groupBy(
            'name'
        )->orderBy(
            'sales_num',
            'desc'
        )->limit(10)->get();

        return $data;
    }

    public function sales_by_day_of_week(Request $request)
    {
        $params = $this->reportParams($request);
        $data = $this->_sales_by_day_of_week(...$params);

        return response()->json($data);
    }

    private function _sales_by_day_of_week(
        $brand_id,
        $start_date,
        $end_date,
        $channel,
        $market,
        $vendor,
        $brand,
        $language,
        $commodity,
        $state
    ) {
        $data = StatsProduct::select(
            DB::raw('DAYOFWEEK(event_created_at) AS day_of_week'),
            DB::raw('DAYNAME(event_created_at) AS day_name'),
            DB::raw('SUM(CASE WHEN result = "Sale" THEN 1 ELSE 0 END) AS sales'),
            DB::raw('SUM(CASE WHEN result = "No Sale" AND disposition_reason NOT IN ("Pending", "Abandoned") THEN 1 ELSE 0 END) AS nosales')
        )->eventRange(
            $start_date,
            $end_date
        );

        if ('client' == session('portal')) {
            $data = $data->where('brand_id', $brand_id);

            if ($vendor) {
                $data = $data->whereIn('vendor_id', $this->listToArray($vendor));
            }
        } else {
            if (2 == session('role_id')) {
                $data = $data->where('office_id', $this->current_office()->id);
            } else {
                $data = $data->where('vendor_id', $brand_id);
            }

            if ($brand) {
                $data = $data->whereIn('brand_id', $this->listToArray($brand));
            }
        }

        if ($channel) {
            $data = $data->whereIn('channel_id', $this->listToArray($channel));
        }

        if ($market) {
            $data = $data->whereIn('market_id', $this->listToArray($market));
        }

        if ($language) {
            $data = $data->whereIn('language_id', $this->listToArray($language));
        }

        if ($commodity) {
            $data = $data->whereIn('commodity_id', $this->listToArray($commodity));
        }

        if ($state) {
            $data = $data->leftJoin(
                'states',
                'stats_product.service_state',
                'states.state_abbrev'
            )->whereIn(
                'states.id',
                $this->listToArray($state)
            );
        }

        $data = $data->groupBy(DB::raw('DAYOFWEEK(event_created_at)'))
            ->orderBy(DB::raw('DAYOFWEEK(event_created_at)'))->get()->toArray();

        $newarray = [];
        foreach ($data as $key => $value) {
            $newarray[$value['day_of_week']] = $value;
        }

        $labels = [];
        $sales = [];
        $nosales = [];

        for ($i = 1; $i < 8; ++$i) {
            switch ($i) {
                case 1:
                    $day = 'Sunday';

                    break;
                case 2:
                    $day = 'Monday';

                    break;
                case 3:
                    $day = 'Tuesday';

                    break;
                case 4:
                    $day = 'Wednesday';

                    break;
                case 5:
                    $day = 'Thursday';

                    break;
                case 6:
                    $day = 'Friday';

                    break;
                case 7:
                    $day = 'Saturday';

                    break;
            }

            if (!isset($newarray[$i])) {
                $newarray[$i] = [
                    'day_of_week' => $i,
                    'day_name' => $day,
                    'sales' => 0,
                    'nosales' => 0,
                ];
            }
        }

        asort($newarray);

        for ($i = 1; $i < 8; ++$i) {
            $labels[] = $newarray[$i]['day_name'];
            $sales[] = (float) $newarray[$i]['sales'];
            $nosales[] = (float) $newarray[$i]['nosales'];
        }

        return [
            'labels' => $labels,
            'sales' => $sales,
            'nosales' => $nosales,
        ];
    }

    private function reportParams(Request $request)
    {
        $brand_id = GetCurrentBrandId();
        $start_date = $request->exists('startDate')
            ? $request->get('startDate')
            : Carbon::today()->format('Y-m-d');
        $end_date = $request->exists('endDate')
            ? $request->get('endDate')
            : Carbon::today()->format('Y-m-d');
        $channel = $request->get('channel');
        $market = $request->get('market');
        $vendor = $request->get('vendor');
        $brand = $request->get('brand');
        $language = $request->get('language');
        $commodity = $request->get('commodity');
        $state = $request->get('state');

        return [
            $brand_id,
            $start_date,
            $end_date,
            $channel,
            $market,
            $vendor,
            $brand,
            $language,
            $commodity,
            $state,
        ];
    }

    private function current_office($noabort = false)
    {
        $office = Office::select(
            'offices.id',
            'offices.name'
        )->join(
            'brand_user_offices',
            'brand_user_offices.office_id',
            'offices.id'
        )->join(
            'brand_users',
            'brand_user_offices.brand_user_id',
            'brand_users.id'
        )->where(
            'brand_users.user_id',
            Auth::user()->id
        )->where(
            'brand_users.employee_of_id',
            session('employee_of_id')
        )->first();
        if (empty($office) && !$noabort) {
            abort(400, 'Invalid Office for user ' . Auth::id());
        }
        return $office;
    }

    public function get_states_by_brand()
    {
        $states = BrandState::select(
            'state_id'
        )->where(
            'brand_id',
            GetCurrentBrandId()
        )->get()->toArray();

        return response()->json($states);
    }

    public function get_brand_states()
    {
        $brand_state = BrandState::select(
            'states.state_abbrev'
        )->leftJoin(
            'states',
            'brand_states.state_id',
            'states.id'
        )->where(
            'brand_states.brand_id',
            GetCurrentBrandId()
        )->get()->toArray();

        return response()->json($brand_state);
    }

    public function get_good_sales_by_brand(Request $request)
    {
        $commodity = $request->get('commodity');
        $channel = $request->get('channel');
        $start_date = $request->get('startDate') ?? Carbon::today()->format('Y-m-d');
        $end_date = $request->get('endDate') ?? Carbon::today()->format('Y-m-d');
        $vendor = $request->get('vendor');
        $language = $request->get('language');
        $state = $request->get('state');
        $market = $request->get('market');

        //dd($request);

        $states = $this->get_brand_states()->getData(true);
        //dd($states);

        $good_sales = StatsProduct::select(
            DB::raw('COUNT(stats_product.id) AS sales'),
            'stats_product.service_state'
        )->eventRange(
            $start_date,
            $end_date
        )->where(
            'stats_product.result',
            'Sale'
        )->where(
            'stats_product.brand_id',
            GetCurrentBrandId()
        )->where(
            'stats_product.stats_product_type_id',
            1
        )->whereNotNull(
            'stats_product.service_state'
        )->whereIn(
            'stats_product.service_state',
            $states
        )->groupBy(
            'stats_product.service_state'
        );

        if ($channel) {
            $good_sales = $good_sales->whereIn(
                'stats_product.channel_id',
                $channel
            );
        }

        if ($market) {
            $good_sales = $good_sales->whereIn(
                'stats_product.market_id',
                $market
            );
        }

        if ($language) {
            $good_sales = $good_sales->whereIn(
                'stats_product.language_id',
                $language
            );
        }

        if ($commodity) {
            $good_sales = $good_sales->whereIn(
                'stats_product.commodity_id',
                $commodity
            );
        }

        if ($state) {
            $good_sales = $good_sales->leftJoin(
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
                $good_sales = $good_sales->whereIn(
                    'stats_product.vendor_id',
                    $vendor
                );
            }
        } else {
            if (session('role_id') === 2) {
                $good_sales = $good_sales->where('stats_product.office_id', $this->current_office()->id);
            } else {
                $good_sales = $good_sales->where(
                    'stats_product.vendor_id',
                    GetCurrentBrandId()
                );
            }
        }

        $good_sales = $good_sales->get()->toArray();

        //dd($good_sales);

        $result = [];
        $aux = [];
        foreach ($good_sales as $sales) {
            $aux[$sales['service_state']] = $sales['sales'];
        }

        foreach ($states as $state) {
            $sales = isset($aux[$state['state_abbrev']]) ? $aux[$state['state_abbrev']] : 0;
            $result[] = ['service_state' => $state['state_abbrev'], 'sales' => $sales];
        }

        return response()->json($result);
    }

    public function get_good_sales_by_county(string $state_param, Request $request)
    {
        $commodity = $request->get('commodity');
        $channel = $request->get('channel');
        $start_date = $request->get('startDate') ?? Carbon::today()->format('Y-m-d');
        $end_date = $request->get('endDate') ?? Carbon::today()->format('Y-m-d');
        $vendor = $request->get('vendor');
        $language = $request->get('language');
        $state = $request->get('state');
        $market = $request->get('market');

        $good_sales_by_county = StatsProduct::select(
            DB::raw('COUNT(stats_product.service_state) AS sales'),
            'stats_product.service_zip',
            'zips.lat',
            'zips.lon',
            'stats_product.service_county'
        )->leftJoin(
            'zips',
            'zips.zip',
            'stats_product.service_zip'
        )->eventRange(
            $start_date,
            $end_date
        )->where(
            'stats_product.result',
            'Sale'
        )->where(
            'stats_product.brand_id',
            GetCurrentBrandId()
        )->where(
            'stats_product.service_state',
            $state_param
        )->groupBy(
            'stats_product.service_county'
        );

        if ($channel) {
            $good_sales_by_county = $good_sales_by_county->whereIn('channel_id', $channel);
        }

        if ($market) {
            $good_sales_by_county = $good_sales_by_county->whereIn('market_id', $market);
        }

        if ($language) {
            $good_sales_by_county = $good_sales_by_county->whereIn('language_id', $language);
        }

        if ($commodity) {
            $good_sales_by_county = $good_sales_by_county->whereIn('commodity_id', $commodity);
        }

        if ($state) {
            $good_sales_by_county = $good_sales_by_county->leftJoin(
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
                $good_sales_by_county = $good_sales_by_county->whereIn('vendor_id', $vendor);
            }
        } else {
            if (session('role_id') === 2) {
                $good_sales_by_county = $good_sales_by_county->where(
                    'stats_product.office_id',
                    $this->current_office()->id
                );
            } else {
                $good_sales_by_county = $good_sales_by_county->where('vendor_id', GetCurrentBrandId());
            }
        }

        $good_sales_by_county = $good_sales_by_county->get()->toArray();

        return response()->json($good_sales_by_county);
    }

    public function salesAgentDashboard()
    {
        return view(
            'dashboard.salesAgentDashboard',
            [
                'vendors' => $this->get_vendors(),
                'languages' => $this->get_languages(),
                'commodities' => $this->get_commodities(),
                'states' => $this->get_states(),
                'brands' => $this->get_brands(),
            ]
        );
    }

    public function getActiveAgents(Request $request)
    {
        $commodity = $request->get('commodity');
        $channel = $request->get('channel');
        $start_date = $request->get('startDate') ?? Carbon::yesterday()->format('Y-m-d');
        $end_date = $request->get('endDate') ?? Carbon::today()->format('Y-m-d');
        $vendor = $request->get('vendor');
        $language = $request->get('language');
        $state = $request->get('state');
        $market = $request->get('market');

        $stats_product = StatsProduct::selectRaw(
            'COUNT(stats_product.sales_agent_id) as active_agents'
        )->eventRange(
            $start_date,
            $end_date
        )->where(
            'stats_product.brand_id',
            GetCurrentBrandId()
        )->whereNotNull('sales_agent_id');

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

        $stats_product = $stats_product->groupBy('sales_agent_id')->get();
        $result = [
            'active_agents' => count($stats_product),
        ];

        return response()->json($result);
    }

    public function avg_sales_per_day(Request $request)
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

        $stats_product = StatsProduct::selectRaw(
            "DATE_FORMAT(stats_product.event_created_at,'%d-%m-%Y') as formated_date,
            COUNT(stats_product.result) as sales"
        )->eventRange(
            $start_date,
            $end_date
        )->where(
            'stats_product.result',
            'Sale'
        )->where(
            'stats_product.brand_id',
            $brand
        );

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

        $stats_product = $stats_product->groupBy('formated_date')->get()->toArray();
        //dd($stats_product);

        $avg = 0;
        if (count($stats_product) > 0) {
            $sales = array_column($stats_product, 'sales');
            $total_s = array_sum($sales);
            $avg = $total_s / count($stats_product);
            $avg = round($avg, 2);
        }

        $result = [
            'avg_sales_per_day' => $avg,
        ];

        return response()->json($result);
    }

    public function avg_agents_active_per_day(Request $request)
    {
        $commodity = $request->get('commodity');
        $channel = $request->get('channel');
        $start_date = $request->get('startDate') ?? Carbon::yesterday()->format('Y-m-d');
        $end_date = $request->get('endDate') ?? Carbon::today()->format('Y-m-d');
        $vendor = $request->get('vendor');
        $language = $request->get('language');
        $state = $request->get('state');
        $market = $request->get('market');

        $stats_product = StatsProduct::selectRaw(
            "COUNT(DISTINCT stats_product.sales_agent_id) as active_agents,
            DATE_FORMAT(stats_product.event_created_at,'%d-%m-%Y') as formated_date"
        )->eventRange(
            $start_date,
            $end_date
        )->where(
            'stats_product.brand_id',
            GetCurrentBrandId()
        )->whereNotNull('sales_agent_id');

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

        $stats_product = $stats_product->groupBy('formated_date');
        $stats_product = $stats_product->get()->toArray();

        $avg = 0;
        if (count($stats_product) > 0) {
            $active_agents = array_column($stats_product, 'active_agents');
            $total_a = array_sum($active_agents);
            $avg = $total_a / count($stats_product);
            $avg = round($avg);
        }

        $result = [
            'avg_agents_active_per_day' => $avg,
        ];

        return response()->json($result);
    }

    public function avg_daily_sales_per_agent(Request $request)
    {
        $commodity = $request->get('commodity');
        $channel = $request->get('channel');
        $start_date = $request->get('startDate') ?? Carbon::yesterday()->format('Y-m-d');
        $end_date = $request->get('endDate') ?? Carbon::today()->format('Y-m-d');
        $vendor = $request->get('vendor');
        $language = $request->get('language');
        $state = $request->get('state');
        $market = $request->get('market');

        $stats_product = StatsProduct::selectRaw(
            "COUNT(stats_product.result) as sales,
            stats_product.sales_agent_id,
            DATE_FORMAT(stats_product.event_created_at,'%d-%m-%Y') as formated_date"
        )->eventRange(
            $start_date,
            $end_date
        )->where(
            'stats_product.result',
            'Sale'
        )->where(
            'stats_product.brand_id',
            GetCurrentBrandId()
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

        $stats_product = $stats_product->groupBy('formated_date');
        //$stats_product = $stats_product->get()->toArray();

        //Working with the subquery
        $querySql = $stats_product->toSql();
        $all_avg = DB::table(DB::raw("($querySql) as avg"))->mergeBindings(
            $stats_product->getQuery()
        )->selectRaw(
            'AVG(sales) as avg'
        )->groupBy('sales_agent_id');

        //dd($all_avg->toSql());
        $all_avg = $all_avg->get()->toArray();

        //dd($all_avg);

        $avg = 0;
        if (count($all_avg) > 0) {
            //dd($all_avg);
            $avgs = array_column($all_avg, 'avg');
            $total_a = array_sum($avgs);
            $avg = round($total_a / count($avgs), 2);
        }

        $result = [
            'avg_daily_sales_per_agent' => $avg,
        ];

        return response()->json($result);
    }

    public function sales_no_sales_by_hour(Request $request)
    {
        $commodity = $request->get('commodity');
        $channel = $request->get('channel');
        $start_date = $request->get('startDate') ?? Carbon::yesterday()->format('Y-m-d');
        $end_date = $request->get('endDate') ?? Carbon::today()->format('Y-m-d');
        $vendor = $request->get('vendor');
        $language = $request->get('language');
        $state = $request->get('state');
        $market = $request->get('market');

        $stats_product = StatsProduct::selectRaw(
            "HOUR(stats_product.event_created_at) as hours,
             SUM(CASE WHEN result = 'Sale' THEN 1 ELSE 0 END) AS sales,
             SUM(CASE WHEN result = 'No Sale' AND disposition_reason NOT IN ('Pending', 'Abandoned') THEN 1 ELSE 0 END) AS no_sales,
             COUNT(DISTINCT DAY(stats_product.event_created_at)) as number_of_days"
        )->where(
            'stats_product.brand_id',
            GetCurrentBrandId()
        )->eventRange(
            $start_date,
            $end_date
        );

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

        $stats_product = $stats_product->orderBy('hours')->groupBy('hours');
        $stats_product = $stats_product->get()->toArray();

        //Formatting the hours to hh:mm
        $stats_product = array_map(function ($elemt) {
            $elemt['hours'] = $elemt['hours'] . ':00';
            //Calculating the AVG
            $elemt['sales'] = (0 != $elemt['sales']) ? round($elemt['sales'] / $elemt['number_of_days'], 2) : 0;
            $elemt['no_sales'] = (0 != $elemt['no_sales']) ? round($elemt['no_sales'] / $elemt['number_of_days'], 2) : 0;

            return $elemt;
        }, $stats_product);

        $hours = ['7:00', '8:00', '9:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00', '23:00'];
        $hours_in_array = array_column($stats_product, 'hours');
        $result = [];
        foreach ($hours as $h) {
            if (!in_array($h, $hours_in_array)) {
                $result[] = [
                    'hours' => $h,
                    'sales' => 0,
                    'no_sales' => 0,
                ];
            } else {
                foreach ($stats_product as $p) {
                    if ($p['hours'] == $h) {
                        $result[] = $p;
                    }
                }
            }
        }

        return response()->json($result);
    }

    public function last_seven_days_s_ns(Request $request)
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

        $a_week_before = (new Carbon($end_date))->subWeek();
        //dd($a_week_before);
        //If start_date is in a range of a week before the end_date then show last 7 days data
        if ((new Carbon($start_date))->between($a_week_before, (new Carbon($end_date)))) {
            $start_date = $a_week_before->format('Y-m-d');
        }
        //dd($start_date);

        $stats_product = StatsProduct::selectRaw(
            "DAYOFWEEK(stats_product.event_created_at) as dayofweek,
             SUM(CASE WHEN result = 'Sale' THEN 1 ELSE 0 END) AS sales,
             SUM(CASE WHEN result = 'No Sale' AND disposition_reason NOT IN ('Pending', 'Abandoned') THEN 1 ELSE 0 END) AS no_sales,
             COUNT(DISTINCT DAY(stats_product.event_created_at)) as number_of_days"
        )->where(
            'stats_product.brand_id',
            $brand
        )->eventRange(
            $start_date,
            $end_date
        );

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

        $stats_product = $stats_product->orderBy('dayofweek')->groupBy('dayofweek');
        $stats_product = $stats_product->get()->toArray();
        //dd($stats_product);

        //Replacing the dayofweek from number to string
        $stats_product = array_map(function ($elemt) {
            //Calculating the AVG
            //Validating division by 0
            $elemt['sales'] = (0 != $elemt['sales']) ? round($elemt['sales'] / $elemt['number_of_days'], 2) : 0;
            $elemt['no_sales'] = (0 != $elemt['no_sales']) ? round($elemt['no_sales'] / $elemt['number_of_days'], 2) : 0;
            switch ($elemt['dayofweek']) {
                case 1:
                    $elemt['dayofweek'] = 'Sunday';

                    break;
                case 2:
                    $elemt['dayofweek'] = 'Monday';

                    break;
                case 3:
                    $elemt['dayofweek'] = 'Tuesday';

                    break;
                case 4:
                    $elemt['dayofweek'] = 'Wednesday';

                    break;
                case 5:
                    $elemt['dayofweek'] = 'Thursday';

                    break;
                case 6:
                    $elemt['dayofweek'] = 'Friday';

                    break;
                case 7:
                    $elemt['dayofweek'] = 'Saturday';

                    break;
            }

            return $elemt;
        }, $stats_product);

        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $days_in_array = array_column($stats_product, 'dayofweek');
        $result = [];
        foreach ($days as $day) {
            if (!in_array($day, $days_in_array)) {
                $result[] = [
                    'dayofweek' => $day,
                    'sales' => 0,
                    'no_sales' => 0,
                ];
            } else {
                foreach ($stats_product as $product) {
                    if ($product['dayofweek'] == $day) {
                        $result[] = $product;
                    }
                }
            }
        }

        return response()->json($result);
    }

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

    private function getPdfInfo(
        $state_id,
        $channel_id,
        $commodity,
        $document_type_id
    ) {
        $pdf_info = BrandEztpvContract::where('brand_id', session('works_for_id'))
            ->where('document_type_id', $document_type_id)
            ->where('state_id', $state_id)
            ->where('channel_id', $channel_id);
        switch ($document_type_id) {
            case 1:
                $pdf_info = $pdf_info->where('commodity', $commodity);

                break;

            case 2:
                // proceed
                break;
        }
        $pdf_info = $pdf_info->first();

        return $pdf_info;
    }

    /**
     * Returns Sales/No Sales for sales agent.
     *
     * @author Wilberto Pacheco Batista
     *
     * @return object
     */
    public function sales_no_sales_by_agent(Request $request)
    {
        $start_date = $request->get('startDate') ?? Carbon::today()->format('Y-m-d');
        $end_date = $request->get('endDate') ?? Carbon::today()->format('Y-m-d');
        $search = $request->get('search');

        $data = StatsProduct::select(
            DB::raw('SUM(CASE WHEN result = "Sale" THEN 1 ELSE 0 END) AS sales'),
            DB::raw('SUM(CASE WHEN result = "No Sale" AND disposition_reason NOT IN ("Pending", "Abandoned") THEN 1 ELSE 0 END) AS nosales')
        )->eventRange(
            $start_date,
            $end_date
        )->join(
            'brand_users',
            'stats_product.sales_agent_id',
            'brand_users.id'
        )->join(
            'users',
            'brand_users.user_id',
            'users.id'
        )->where(
            'users.id',
            Auth::user()->id
        );

        if (null != $search) {
            $data = $data->interactionSearch($search);
        }

        $data = $data->get();

        if (isset($data[0])) {
            $data = $data[0];
            $data->total = $data['sales'] + $data['nosales'];
            $data->sale_percentage = (isset($data['sales'])
                && $data['sales'] > 0
                && $data->total > 0) ? number_format(
                ($data['sales'] / $data->total) * 100,
                2
            ) : 0;
            $data->no_sale_percentage = (isset($data['nosales'])
                && $data['nosales'] > 0
                && $data->total > 0) ? number_format(
                ($data['nosales'] / $data->total) * 100,
                2
            ) : 0;
        } else {
            $data = null;
        }

        return $data;
    }

    /**
     * Returns Sales/No Sales Dataset for sales agent.
     *
     * @author Wilberto Pacheco Batista
     *
     * @return array
     */
    public function s_no_s_dataset_by_agent(Request $request)
    {
        $start_date = $request->get('startDate') ?? Carbon::today()->format('Y-m-d');
        $end_date = $request->get('endDate') ?? Carbon::today()->format('Y-m-d');
        $search = $request->get('search');

        if ($start_date == $end_date) {
            $data = StatsProduct::select(
                DB::raw('HOUR(event_created_at) AS the_dates'),
                DB::raw('SUM(CASE WHEN result = "Sale" THEN 1 ELSE 0 END) AS sales'),
                DB::raw('SUM(CASE WHEN result = "No Sale" AND disposition_reason NOT IN ("Pending", "Abandoned") THEN 1 ELSE 0 END) AS nosales')
            )->eventRange(
                $start_date,
                $end_date
            )->join(
                'brand_users',
                'stats_product.sales_agent_id',
                'brand_users.id'
            )->join(
                'users',
                'brand_users.user_id',
                'users.id'
            )->where(
                'users.id',
                Auth::user()->id
            );

            if (null != $search) {
                $data = $data->interactionSearch($search);
            }

            $data = $data->groupBy(
                DB::raw('HOUR(event_created_at)')
            )->get()->toArray();

            $labels = [7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23];

            $newarray = [];
            for ($i = 0; $i < count($data); ++$i) {
                $newarray[$data[$i]['the_dates']] = $data[$i];
            }

            for ($i = 7; $i < 24; ++$i) {
                if (!isset($newarray[$i])) {
                    $newarray[$i] = [
                        'the_dates' => $i,
                        'sales' => 0,
                        'nosales' => 0,
                    ];
                }
            }

            asort($newarray);

            $sales = [];
            $nosales = [];

            foreach ($newarray as $na) {
                $sales[] = $na['sales'];
                $nosales[] = $na['nosales'];
            }
        } else {
            $data = StatsProduct::select(
                DB::raw('DATE_FORMAT(stats_product.event_created_at, "%Y-%m-%d") AS the_dates'),
                DB::raw('SUM(CASE WHEN stats_product.result = "Sale" THEN 1 ELSE 0 END) AS sales'),
                DB::raw('SUM(CASE WHEN stats_product.result = "No Sale" AND disposition_reason NOT IN ("Pending", "Abandoned") THEN 1 ELSE 0 END) AS nosales')
            )->eventRange(
                $start_date,
                $end_date
            )->join(
                'brand_users',
                'stats_product.sales_agent_id',
                'brand_users.id'
            )->join(
                'users',
                'brand_users.user_id',
                'users.id'
            )->where(
                'users.id',
                Auth::user()->id
            );

            if (null != $search) {
                $data = $data->interactionSearch($search);
            }

            $data = $data->groupBy(
                DB::raw('DATE_FORMAT(event_created_at, "%Y-%m-%d")')
            )->get()->toArray();

            $labels = [];
            $period = \Carbon\CarbonPeriod::create($start_date, $end_date);
            foreach ($period as $date) {
                $labels[] = $date->format('Y-m-d');
            }

            $newarray = [];
            for ($i = 0; $i < count($data); ++$i) {
                $newarray[$data[$i]['the_dates']] = $data[$i];
            }

            foreach ($labels as $label) {
                if (!isset($newarray[$label])) {
                    $newarray[$label] = [
                        'the_dates' => $label,
                        'sales' => 0,
                        'nosales' => 0,
                    ];
                }
            }

            asort($newarray);

            $sales = [];
            $nosales = [];

            foreach ($newarray as $na) {
                $sales[] = $na['sales'];
                $nosales[] = $na['nosales'];
            }
        }

        return [
            'labels' => $labels,
            'nosales' => $nosales,
            'sales' => $sales,
        ];
    }

    public function globo_search()
    {
        //dd(session()->all());
        if (!request()->ajax()) {
            return view('search.results');
        }

        $query = request()->input('query');
        $page = request()->input('page');
        $perPage = request()->input('perPage');
        if (!is_numeric($perPage)) {
            $perPage = 15;
        }
        if (!is_numeric($page)) {
            $page = 1;
        }

        $type = request()->input('type');
        if ($query == null) {
            $results = collect([]);
        } else {

            switch ($type) {
                case 'vendor':
                    $searchFuncs = [
                        'search_Vendors',
                    ];
                    break;
                case 'office':
                    $searchFuncs = [
                        'search_Offices',
                    ];
                    break;

                case 'users':
                    $searchFuncs = [
                        'search_BrandUser',
                    ];
                    break;
                case 'events':
                    $searchFuncs = [
                        'search_StatsProduct',
                    ];
                    break;

                default:
                    $type = '';
                    $searchFuncs = [
                        'search_BrandUser',
                        'search_StatsProduct',
                        'search_Offices',
                    ];
                    if(session('portal') !== 'vendor'){
                        array_push($searchFuncs,'search_Vendors');
                    }
                    break;
            }
            $results = collect([]);

            foreach ($searchFuncs as $func) {
                $t = $this->$func($query, $page, $perPage);
                if ($t !== null) {
                    $results = $results->concat($t);
                }
            }
        }

        /*if (is_object($results) && $results->count() == 1 && $type == 'StatsProduct') {
            return redirect('/events/' . $results->first()->event_id);
        }*/
        if (is_object($results)) {

            $results = $results->sortByDesc('created_at');
            $results = $results->paginate($perPage);
            //dd($results);
        }

        return [
            'results' => $results->all(),
            'query' => $query,
            'page' => $results->currentPage(),
            'lastPage' => $results->lastPage(),
            'perPage' => $perPage,
            'total' => $results->total(),
            'type' => $type == null ? '' : $type,
        ];
    }

    private function search_Vendors($query, $currentPage, $perPage)
    {
        $results = null;
        if ($query !== null) {
            $brand = GetCurrentBrandId();

            $results = Vendor::select('vendors.*', 'brands.name', DB::raw('"Vendors" as result_type'))
                ->leftJoin('brands', 'brands.id', 'vendors.vendor_id')
                ->where(function ($q) use ($query, $brand) {
                    $q->where('vendors.vendor_label', 'like', '%' . $query . '%')
                        ->where('vendors.brand_id', $brand);
                })
                ->orWhere(function ($q) use ($query, $brand) {
                    $q->where('brands.name', 'like', '%' . $query . '%')
                        ->where('vendors.brand_id', $brand);
                });
        }
        if ($results !== null) {
            return $results->limit(100)->get();
        }
        return null;
    }

    private function search_Offices($query, $currentPage, $perPage)
    {
        $results = null;
        if ($query !== null) {
            $brand = GetCurrentBrandId();
            $portalType = session('portal');

            $results = Office::select('offices.*', 'vendor.name as vendor_name', DB::raw('"Offices" as result_type'))
                ->join('brands', 'brands.id', 'offices.brand_id')
                ->leftJoin('vendors', 'vendors.id', 'offices.vendor_id')
                ->leftJoin('brands as vendor', 'vendor.id', 'vendors.vendor_id')
                ->where('offices.brand_id', $brand);
            //dd($results->toSql());

            if ($portalType == 'vendor') {
                $results = $results->where('offices.vendor_id', session('employee_of_id'));
            }
            $results = $results->where('offices.name', 'like', '%' . $query . '%')
                ->whereNotNull('vendor.name');
        }
        if ($results !== null) {
            return $results->limit(100)->get();
        }
        return null;
    }

    private function search_BrandUser($query, $currentPage, $perPage)
    {
        $results = null;
        if ($query !== null) {
            $parts = explode(' ', $query);
            if(session('portal') == 'vendor') {
                $vendorBrands = Vendor::select('brand_id')->where('vendor_id', session('employee_of_id'))->get();//->pluck('brand_id');
                $brands = [];
                foreach($vendorBrands as $brand)
                {
                    $brands[] = $brand->brand_id;
                }
            } else {
                $brands = GetCurrentBrandId();
            }
            switch (count($parts)) {
                case 1:
                    
                    $results = BrandUser::select('brand_users.*', 'vendors.id as vendor_id', 'offices.id as real_office_id', 'offices.name as office_name', DB::raw('"BrandUser" as result_type'))
                        ->leftJoin('users', 'users.id', 'brand_users.user_id')
                        ->leftJoin('brand_user_offices', 'brand_user_offices.brand_user_id', 'brand_users.id')
                        ->leftJoin('offices', 'offices.id', 'brand_user_offices.office_id')
                        ->leftJoin('vendors', 'vendors.vendor_id', 'brand_users.employee_of_id')
                        ->with(['user', 'works_for', 'employee_of', 'role'])
                        ->where(function ($q) use ($query) {
                            $q->where('brand_users.works_for_id', GetCurrentBrandId())
                                ->where('users.first_name', 'like', '%' . $query . '%')
                                ->where('vendors.brand_id', GetCurrentBrandId());
                            if(session('portal') == 'vendor') {
                                $q->where('vendors.vendor_id', session('employee_of_id'));
                            }
                        })
                        ->orWhere(function ($q) use ($query) {
                            $q->where('brand_users.works_for_id', GetCurrentBrandId())
                                ->where('users.last_name', 'like', '%' . $query . '%')
                                ->where('vendors.brand_id', GetCurrentBrandId());
                            if(session('portal') == 'vendor') {
                                $q->where('vendors.vendor_id', session('employee_of_id'));
                            }
                        })
                        ->orWhere(function ($q) use ($query) {
                            $q->where('brand_users.works_for_id', GetCurrentBrandId())
                                ->where('brand_users.tsr_id', 'like', '%' . $query . '%')
                                ->where('vendors.brand_id', GetCurrentBrandId());
                            if(session('portal') == 'vendor') {
                                $q->where('vendors.vendor_id', session('employee_of_id'));
                            }
                        });
                    break;
                case 2:
                    $f_name = $parts[0];
                    $l_name = $parts[1];
                    $results = BrandUser::select('brand_users.*', 'vendors.id as vendor_id', 'offices.id as real_office_id', 'offices.name as office_name', DB::raw('"BrandUser" as result_type'))
                        ->leftJoin('users', 'users.id', 'brand_users.user_id')
                        ->leftJoin('brand_user_offices', 'brand_user_offices.brand_user_id', 'brand_users.id')
                        ->leftJoin('offices', 'offices.id', 'brand_user_offices.office_id')
                        ->leftJoin('vendors', 'vendors.vendor_id', 'brand_users.employee_of_id')
                        ->with(['user', 'works_for', 'employee_of', 'role'])
                        ->where(function ($q) use ($f_name, $l_name) {
                                $q->where('brand_users.works_for_id', GetCurrentBrandId())
                                    ->where('users.first_name', 'like', '%' . $f_name . '%')
                                    ->where('users.last_name', 'like', '%' . $l_name . '%')
                                    ->where('vendors.brand_id', GetCurrentBrandId());
                                    if(session('portal') == 'vendor') {
                                        $q->where('vendors.vendor_id', session('employee_of_id'));
                                    }
                            });
                    break;
            }
        }
        if ($results !== null) {
            $results = $results->orderBy('brand_users.created_at', 'DESC')->limit(100)->get();
        }

        if($results->count() == 0){
            return null;
        }
        return $results;
    }

    private function search_StatsProduct($query, $currentPage, $perPage)
    {
        $results = null;
        if (!is_numeric($query)) {
            $parts = explode(' ', $query);
            switch (count($parts)) {
                case 2:
                    $f_name = $parts[0];
                    $l_name = $parts[1];
                    $results = StatsProduct::select('*', DB::raw('"StatsProduct" as result_type'));

                    if(session('portal') == 'vendor') {
                        $vendorBrands = Vendor::select('brand_id')->where('vendor_id', session('employee_of_id'))->get();//->pluck('brand_id');
                        $brands = [];
                        foreach($vendorBrands as $brand)
                        {
                            $brands[] = $brand->brand_id;
                        }
                    } else {
                        $brands = GetCurrentBrandId();
                    }
                                    
                    $results = $results->where(function ($q) use ($f_name, $l_name, $brands) {
                        $q->where('bill_first_name', 'like', '%' . $f_name . '%')
                            ->where('bill_last_name', 'like', '%' . $l_name . '%');
                            if(session('portal') == 'vendor'){
                                $q->where('stats_product.vendor_id', session('employee_of_id'))
                                ->whereIn('brand_id', $brands);
                            }else{
                                $q->where('brand_id', $brands);
                            }
                    })
                    ->orWhere(function ($q) use ($query, $brands) {
                        $q->where('sales_agent_name', 'LIKE', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    })
                    ->orWhere(function ($q) use ($query, $brands) {
                        $q->where('company_name', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    })
                    ->orWhere(function ($q) use ($query, $brands) {
                        $q->where('email_address', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    })
                    ->orWhere(function ($q) use ($f_name, $l_name, $brands) {
                        $q->where('auth_first_name', 'like', '%' . $f_name . '%')
                            ->where('auth_last_name', 'like', '%' . $l_name . '%');
                            if(session('portal') == 'vendor'){
                                $q->where('stats_product.vendor_id', session('employee_of_id'))
                                ->whereIn('brand_id', $brands);
                            }else{
                                $q->where('brand_id', $brands);
                            }
                    })->orWhere(function ($q) use ($query, $brands) {
                        $q->where('service_address1', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    });
                    break;
                case 3:
                    $f_name = $parts[0];
                    $m_name = $parts[1];
                    $l_name = $parts[2];
                    $results = StatsProduct::select('*', DB::raw('"StatsProduct" as result_type'));

                    if(session('portal') == 'vendor') {
                        $vendorBrands = Vendor::select('brand_id')->where('vendor_id', session('employee_of_id'))->get();//->pluck('brand_id');
                        $brands = [];
                        foreach($vendorBrands as $brand)
                        {
                            $brands[] = $brand->brand_id;
                        }
                    } else {
                        $brands = GetCurrentBrandId();
                    }

                    $results = $results->where(function ($q) use ($f_name, $m_name, $l_name, $brands) {
                        $q->where('bill_first_name', 'like', '%' . $f_name . '%')
                            ->where('bill_middle_name', 'like', '%' . $m_name . '%')
                            ->where('bill_last_name', 'like', '%' . $l_name . '%');
                            if(session('portal') == 'vendor'){
                                $q->where('stats_product.vendor_id', session('employee_of_id'))
                                ->whereIn('brand_id', $brands);
                            }else{
                                $q->where('brand_id', $brands);
                            }
                    })
                    ->orWhere(function ($q) use ($query, $brands) {
                        $q->where('sales_agent_name', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    })
                    ->orWhere(function ($q) use ($query, $brands) {
                        $q->where('company_name', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    })
                    ->orWhere(function ($q) use ($query) {
                        $q->where('email_address', 'like', '%' . $query . '%');
                    })
                    ->orWhere(function ($q) use ($f_name, $m_name, $l_name, $brands) {
                        $q->where('auth_first_name', 'like', '%' . $f_name . '%')
                            ->where('auth_middle_name', 'like', '%' . $m_name . '%')
                            ->where('auth_last_name', 'like', '%' . $l_name . '%');
                            if(session('portal') == 'vendor'){
                                $q->where('stats_product.vendor_id', session('employee_of_id'))
                                ->whereIn('brand_id', $brands);
                            }else{
                                $q->where('brand_id', $brands);
                            }
                    })->orWhere(function ($q) use ($query, $brands) {
                        $q->where('service_address1', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    });
                    break;

                default:
                    $results = StatsProduct::select('*', DB::raw('"StatsProduct" as result_type'));
                    if(session('portal') == 'vendor') {
                        $vendorBrands = Vendor::select('brand_id')->where('vendor_id', session('employee_of_id'))->get();//->pluck('brand_id');
                        $brands = [];
                        foreach($vendorBrands as $brand)
                        {
                            $brands[] = $brand->brand_id;
                        }
                    } else {
                        $brands = GetCurrentBrandId();
                    }
                    $results = $results->where(function ($q) use ($query, $brands) {
                        $q->where('bill_first_name', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    })
                    ->orWhere(function ($q) use ($query, $brands) {
                        $q->where('bill_last_name', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    })
                    ->orWhere(function ($q) use ($query, $brands) {
                        $q->where('auth_first_name', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    })
                    ->orWhere(function ($q) use ($query, $brands) {
                        $q->where('auth_last_name', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    })
                    ->orWhere(function ($q) use ($query, $brands) {
                        $q->where('company_name', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    })
                    ->orWhere(function ($q) use ($query, $brands) {
                        $q->where('email_address', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    })
                    ->orWhere(function ($q) use ($query, $brands) {
                        $q->where('service_address1', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    });
                }
        } else {
            if (strlen((string) $query) == 11) {
                $results = StatsProduct::select('*', DB::raw('"StatsProduct" as result_type'));
                if(session('portal') == 'vendor') {
                    $vendorBrands = Vendor::select('brand_id')->where('vendor_id', session('employee_of_id'))->get();//->pluck('brand_id');
                    $brands = [];
                    foreach($vendorBrands as $brand)
                    {
                        $brands[] = $brand->brand_id;
                    }
                } else {
                    $brands = GetCurrentBrandId();
                }
                $results = $results->where('confirmation_code', $query)
                    ->orWhere(function ($q) use ($query, $brands) {
                        $q->where('account_number1', 'like', '%' . $query . '%');
                        if(session('portal') == 'vendor'){
                            $q->where('stats_product.vendor_id', session('employee_of_id'))
                            ->whereIn('brand_id', $brands);
                        }else{
                            $q->where('brand_id', $brands);
                        }
                    });
            } else {
                $results = StatsProduct::select('*', DB::raw('"StatsProduct" as result_type'))
                    ->where(function ($q) use ($query) {
                        $q->where('brand_id', GetCurrentBrandId())
                            ->where('account_number1', 'like', '%' . $query . '%');
                    })

                    ->orWhere(function ($q) use ($query) {
                        $q->where('brand_id', GetCurrentBrandId())
                            ->where('confirmation_code', 'like', '%' . $query . '%');
                    })

                    ->orWhere(function ($q) use ($query) {
                        $q->where('brand_id', GetCurrentBrandId())
                            ->where('btn', 'like', '%' . $query . '%');
                    });
            }
        }

        if ($results !== null) {
            $results = $results->orderBy('created_at', 'DESC')->limit(100)->get();
        }
        if($results->count() == 0){
            return null;
        }
        return $results;
    }
}
