<?php

namespace App\Http\Controllers\Auth;
use App\Tables\RoleTableConfigurator;
use App\Http\Controllers\Controller;
use ProtoneMedia\Splade\Facades\Splade;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use App\Http\Requests\RoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use Illuminate\Http\Request;

class RoleUserController extends Controller
{
    public function index()
    {  
        return view('role.index', [
            'role' => new RoleTableConfigurator()
        ]);
    }

        /**
     * Display the registration view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {

       
        $roles = Role::all(['id', 'name']);
        $permissions = Permission::all(['id', 'name']);
        $selectedRole = null;
        $rolesWithPermissions = [];
        foreach ($roles as $role) {
            $rolesWithPermissions[$role->id] = $role->permissions->pluck('id')->toArray();
        }
        $middleware = collect(Route::current()->gatherMiddleware())->toArray();
        $path = in_array('auth', $middleware) ? 'role.create' : 'role.create_guest_agent';
        return view($path, [
            'middleware' => $middleware,
            'roles' => $roles,
            'permissions' => $permissions,
            'rolesWithPermissions' => $rolesWithPermissions,
            'selectedRole' => $selectedRole,
        ]);
    }



    /**
     * @param RoleRequest $request
     * @return array
     */
    public function roleData(RoleRequest $request): array
    {

        return [
            'name' => $request->name,
            'guard_name' => 'web'
        ];
    }


    public function store(RoleRequest $request)
    {

        // Check if the authenticated user is an admin
        $isCreatedByAdmin = auth()->check() && auth()->user()->hasRole('admin'); // Assuming you're using a roles system

        // Create the User with `created_by_admin` flag
        $role = Role::create(
            $this->roleData($request)
        );

        // Assign permissions to the role
        if ($request->filled('permissions')) {
            $permissions = Permission::whereIn('id', $request->input('permissions'))->pluck('name')->toArray();
            $role->givePermissionTo($permissions);
            // $role->syncPermissions($permissions); // Sync permissions with the role
        }

        // Redirect to agent index if created by admin
        return Redirect::route('role.index')->with('status', 'role-created');
    }

    public function edit($id)
    {
        $role = Role::where('id', $id)->first();
        $permissions = Permission::all(['id', 'name']);
        $rolePermissions = $role->permissions->pluck('id')->toArray();
        return view('role.edit', [
            'role' => $role,
            'permissions' => $permissions,
            'rolePermissions' => $rolePermissions
        ]);
    }

    public function update(UpdateRoleRequest $request, $id)
    {
        // Fetch the role by its ID
        $role = Role::findOrFail($id);
    
        // Update role name if it’s allowed to be edited
        $role->name = $request->input('name');
        $role->save();
    
        // Sync permissions for the role
        if ($request->filled('permissions')) {
            $permissions = Permission::whereIn('id', $request->input('permissions'))->get();
            $role->syncPermissions($permissions);
        } else {
            // If no permissions are selected, remove all permissions from the role
            $role->syncPermissions([]);
        }
    
        Splade::toast('Role updated successfully!')->success();

        return Redirect::route('role.edit', ['role' => $role->id])->with('status', 'role-updated');
    }
}
