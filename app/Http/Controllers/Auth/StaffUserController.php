<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Tables\StaffTableConfigurator;
use App\Models\Country;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use ProtoneMedia\Splade\Facades\Splade;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class StaffUserController extends Controller
{

    /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {

        $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        $roles = Role::all(['id', 'name']);
        $permissions = Permission::all(['id', 'name']);
        $selectedRole = null;
        $rolesWithPermissions = [];
        foreach ($roles as $role) {
            $rolesWithPermissions[$role->id] = $role->permissions->pluck('id')->toArray();
        }
        $middleware = collect(Route::current()->gatherMiddleware())->toArray();
        $path = in_array('auth', $middleware) ? 'staff.create' : 'staff.create_guest_agent';
        return view($path, [
            'countries' => $countries,
            'middleware' => $middleware,
            'roles' => $roles,
            'permissions' => $permissions,
            'rolesWithPermissions' => $rolesWithPermissions,
            'selectedRole' => $selectedRole,
            'isCreating' => true,
        ]);
    }


    public function index()
    {  
        return view('staff.index', [
            'staff' => new StaffTableConfigurator()
        ]);
    }

    /**
     * @param StoreStaffRequest $request
     * @return array
     */
    public function userData(StoreStaffRequest $request): array
    {
        $emailToken = sha1($request->email);
        $data = [
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'username' => $request->username,
            'phone' => $request->phone,
            'phone_code' => $request->phone_code,
            'email_verification_token' => $emailToken,
            "designation" => $request->designation,
            "mobile" => $request->mobile,
            'type' => 'staff',
        ];

        if($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        return $data;
    }


    /**
     * @param StoreStaffRequest $request
     * @return array
     */
    public function updateUserData(UpdateStaffRequest $request): array
    {
        $emailToken = sha1($request->email);
        $data = [
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'username' => $request->username,
            'phone' => $request->phone,
            'phone_code' => $request->phone_code,
            'email_verification_token' => $emailToken,
            "designation" => $request->designation,
            "mobile" => $request->mobile,
            'type' => 'staff',
        ];

        return $data;
    }

    public function store(StoreStaffRequest $request)
    {

        // Check if the authenticated user is an admin
        $isCreatedByAdmin = auth()->check() && auth()->user()->hasRole('admin'); // Assuming you're using a roles system
         $loggedInUser = auth()->user(); // Fetch the logged-in user

        // Create the User with `created_by_admin` flag
        $user = User::create(array_merge(
            $this->userData($request),
            [
                'created_by_admin' => $isCreatedByAdmin,
                'approved' => $isCreatedByAdmin,
                'agent_code' => $loggedInUser->agent_code,
            ] // Set approved flag based on admin creation
        ));


            // Assign role to user
        if ($request->filled('role')) {
            $role = Role::findById($request->input('role'))->name ?? null;
            $user->assignRole($role);
        }

        // Assign permissions to user
        if ($request->filled('permissions')) {
            $permissions = Permission::whereIn('id', $request->input('permissions'))->pluck('name')->toArray();
            $user->syncPermissions($permissions);
        }

        // Redirect to agent index if created by admin
        return Redirect::route('staff.index')->with('status', 'staff-created');
    }

    public function unapprove($id)
    {
        $agent = User::findOrFail($id);
        $agent->approved = false;
        $agent->save();

        return redirect()->route('staff.index')->with('status', 'Staff unapproved successfully!');
    }

    public function approve($id)
    {
        $agent = User::findOrFail($id);
        $agent->approved = true;
        $agent->save();

        return redirect()->route('staff.index')->with('status', 'Staff approved successfully!');
    }

    public function edit($id)
    {
        $roles = Role::all(['id', 'name']);
        $permissions = Permission::all(['id', 'name']);
        $selectedRole = null;
        $rolesWithPermissions = [];
        foreach ($roles as $role) {
            $rolesWithPermissions[$role->id] = $role->permissions->pluck('id')->toArray();
        }
        $user = User::with(['roles', 'permissions'])->where('id', $id)->first();
        $countries = Country::all(['id', 'name'])->pluck('name', 'id');
        $userData = [
            'id' => $user->id,
            'email' => $user->email,
            'username' => $user->username,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'designation' => $user->designation,
            'phone_code' => $user->phone_code,
            'phone' => $user->phone,
            'mobile' => $user->mobile,
            'preferred_currency' => $user->preferred_currency,
            'role' => $user->roles->first()?->id, 
            'permissions' => $user->permissions->pluck('id')->toArray(), 
            'rolesWithPermissions' => $rolesWithPermissions,
        ];
        return view('staff.edit', [
            'user' => $user,
            'userData' => $userData, 
            'countries' => $countries,
            'roles' => $roles,
            'permissions' => $permissions,
            'rolesWithPermissions' => $rolesWithPermissions,
            'selectedRole' => $selectedRole,
            'isCreating' => false,
        ]);
    }

    public function update(UpdateStaffRequest $request, $staff)
    {
        $user = User::where('id', $staff)->first();
        $user->update($this->updateUserData($request));

        // Step 1: Remove all roles
        if ($request->filled('role')) {
            $role = Role::findById($request->input('role'))->name ?? null;
            $user->syncRoles([$role]); // Clear roles to ensure no role-based permissions
        }

        // Step 2: Sync direct permissions from the request
        if ($request->filled('permissions')) {
            $permissions = Permission::whereIn('id', $request->input('permissions'))->pluck('name')->toArray();
            $user->syncPermissions([]); // Clear all current permissions
            $user->syncPermissions($permissions); // Assign only requested permissions
        } else {
            $user->syncPermissions([]); // Remove all permissions if none are provided
        }
        Splade::toast('Staff updated successfully!')->success();

        return Redirect::route('staff.edit', ['staff' => $user->id])->with('status', 'profile-updated');
    }




}
