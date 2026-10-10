<?php
$routes->setAutoRoute(false);

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

$routes->get('/', 'Home::index');

$routes->get('api/health', function () {
    return service('response')->setJSON([
        'success' => true,
        'message' => 'NPCRM API running'
    ]);
});

$routes->group('api', ['namespace' => 'App\Controllers\Api'], function($routes){

    // =========================
    // PUBLIC
    // =========================
    $routes->post('auth/login', 'AuthController::login');
	



    // =========================
    // PROTECTED (JWT)
    // =========================
    $routes->group('', ['filter' => 'auth'], function($routes){

        $routes->get('auth/me', 'AuthController::me');
        $routes->post('auth/logout', 'AuthController::logout');
        
        	// SELF ATTENDANCE - keep outside auth filter
        $routes->get('attendance/self/today', 'AttendanceController::selfToday');
        $routes->get('attendance/self/history', 'AttendanceController::selfHistory');
        $routes->post('attendance/self/login-capture', 'AttendanceController::selfLoginCapture');
        $routes->post('attendance/self/logout-capture', 'AttendanceController::selfLogoutCapture');

        // COMPANY LOCATION & GEOFENCE MAP
        $routes->get('company-location', 'CompanyLocationController::show');
        $routes->post('company-location', 'CompanyLocationController::save');
        $routes->get('attendance/map-locations', 'CompanyLocationController::mapLocations');

        // ---------------------------------
        // SERVICES (master)
        // ---------------------------------
        $routes->group('services', function($routes){

            // view
            $routes->get('/', 'ServiceController::index', ['filter' => 'perm:services.view']);
            $routes->get('(:num)', 'ServiceController::show/$1', ['filter' => 'perm:services.view']);

            // manage
            $routes->post('/', 'ServiceController::create', ['filter' => 'perm:services.manage']);
            $routes->put('(:num)', 'ServiceController::update/$1', ['filter' => 'perm:services.manage']);
            $routes->delete('(:num)', 'ServiceController::delete/$1', ['filter' => 'perm:services.manage']);

            // ---------------------------------
            // DOC TEMPLATES (by service)
            // /api/services/{serviceId}/doc-templates
            // ---------------------------------
            $routes->get('(:num)/doc-templates', 'ServiceDocTemplateController::index/$1', [
                'filter' => 'perm:service_doc_templates.view'
            ]);
            $routes->post('(:num)/doc-templates', 'ServiceDocTemplateController::create/$1', [
                'filter' => 'perm:service_doc_templates.manage'
            ]);

            // ---------------------------------
            // WORKFLOW TEMPLATES (by service)
            // /api/services/{serviceId}/workflow-templates
            // ---------------------------------
            $routes->get('(:num)/workflow-templates', 'ServiceWorkflowTemplateController::index/$1', [
                'filter' => 'perm:service_workflow_templates.view'
            ]);
            $routes->post('(:num)/workflow-templates', 'ServiceWorkflowTemplateController::create/$1', [
                'filter' => 'perm:service_workflow_templates.manage'
            ]);

            // ---------------------------------
            // TASK TEMPLATES (by service)
            // /api/services/{serviceId}/task-templates
            // ---------------------------------
            $routes->get('(:num)/task-templates', 'ServiceTaskTemplateController::index/$1', [
                'filter' => 'perm:service_task_templates.view'
            ]);
            $routes->post('(:num)/task-templates', 'ServiceTaskTemplateController::create/$1', [
                'filter' => 'perm:service_task_templates.manage'
            ]);
        });

        // =========================================================
        // DOC TEMPLATE CRUD + activation + items
        // =========================================================

        // Doc Template CRUD (single)
        $routes->get('doc-templates/(:num)', 'ServiceDocTemplateController::show/$1', [
            'filter' => 'perm:service_doc_templates.view'
        ]);
        $routes->put('doc-templates/(:num)', 'ServiceDocTemplateController::update/$1', [
            'filter' => 'perm:service_doc_templates.manage'
        ]);
        $routes->patch('doc-templates/(:num)', 'ServiceDocTemplateController::update/$1', [
            'filter' => 'perm:service_doc_templates.manage'
        ]);
        $routes->put('doc-templates/(:num)/activate', 'ServiceDocTemplateController::activate/$1', [
            'filter' => 'perm:service_doc_templates.manage'
        ]);
        $routes->put('doc-templates/(:num)/deactivate', 'ServiceDocTemplateController::deactivate/$1', [
            'filter' => 'perm:service_doc_templates.manage'
        ]);
        $routes->delete('doc-templates/(:num)', 'ServiceDocTemplateController::delete/$1', [
            'filter' => 'perm:service_doc_templates.manage'
        ]);

        // Doc Template items
        $routes->get('doc-templates/(:num)/items', 'ServiceDocTemplateItemController::index/$1', [
            'filter' => 'perm:service_doc_template_items.view'
        ]);
        $routes->post('doc-templates/(:num)/items', 'ServiceDocTemplateItemController::create/$1', [
            'filter' => 'perm:service_doc_template_items.manage'
        ]);
        $routes->put('doc-template-items/(:num)', 'ServiceDocTemplateItemController::update/$1', [
            'filter' => 'perm:service_doc_template_items.manage'
        ]);
        $routes->patch('doc-template-items/(:num)', 'ServiceDocTemplateItemController::update/$1', [
            'filter' => 'perm:service_doc_template_items.manage'
        ]);
        $routes->delete('doc-template-items/(:num)', 'ServiceDocTemplateItemController::delete/$1', [
            'filter' => 'perm:service_doc_template_items.manage'
        ]);
        
        
        // ============================================================
// CORRECTED ROUTES — Add inside your existing API route group
// Since your route group already adds the 'Api\' namespace,
// we must NOT repeat it in the controller path.
// ============================================================

// --- Quotation Templates (per service) ---
$routes->get('services/(:num)/quotation-templates', 'ServiceQuotationTemplateController::listByService/$1');
$routes->post('services/(:num)/quotation-templates', 'ServiceQuotationTemplateController::create/$1');

// --- Single Quotation Template ---
$routes->get('quotation-templates/(:num)', 'ServiceQuotationTemplateController::get/$1');
$routes->put('quotation-templates/(:num)', 'ServiceQuotationTemplateController::update/$1');
$routes->delete('quotation-templates/(:num)', 'ServiceQuotationTemplateController::delete/$1');

// --- Signature Upload ---
$routes->post('quotation-templates/(:num)/upload-signature', 'ServiceQuotationTemplateController::uploadSignature/$1');

// --- Quotation Template Items ---
$routes->get('quotation-templates/(:num)/items', 'ServiceQuotationTemplateItemController::list/$1');
$routes->post('quotation-templates/(:num)/items', 'ServiceQuotationTemplateItemController::create/$1');
$routes->put('quotation-template-items/(:num)', 'ServiceQuotationTemplateItemController::update/$1');
$routes->delete('quotation-template-items/(:num)', 'ServiceQuotationTemplateItemController::delete/$1');

 

        
        // =========================================================
        // WORKFLOW TEMPLATE activation + steps
        // =========================================================
        $routes->put('workflow-templates/(:num)/activate', 'ServiceWorkflowTemplateController::activate/$1', [
            'filter' => 'perm:service_workflow_templates.manage'
        ]);

        $routes->get('workflow-templates/(:num)/steps', 'ServiceWorkflowStepController::index/$1', [
            'filter' => 'perm:service_workflow_steps.view'
        ]);
        $routes->post('workflow-templates/(:num)/steps', 'ServiceWorkflowStepController::create/$1', [
            'filter' => 'perm:service_workflow_steps.manage'
        ]);
        $routes->put('workflow-steps/(:num)', 'ServiceWorkflowStepController::update/$1', [
            'filter' => 'perm:service_workflow_steps.manage'
        ]);
        $routes->delete('workflow-steps/(:num)', 'ServiceWorkflowStepController::delete/$1', [
            'filter' => 'perm:service_workflow_steps.manage'
        ]);

        // =========================================================
        // TASK TEMPLATE CRUD + items
        // =========================================================

        // Task Template CRUD (single)
        $routes->get('task-templates/(:num)', 'ServiceTaskTemplateController::show/$1', [
            'filter' => 'perm:service_task_templates.view'
        ]);
        $routes->put('task-templates/(:num)', 'ServiceTaskTemplateController::update/$1', [
            'filter' => 'perm:service_task_templates.manage'
        ]);
        $routes->patch('task-templates/(:num)', 'ServiceTaskTemplateController::update/$1', [
            'filter' => 'perm:service_task_templates.manage'
        ]);
        $routes->delete('task-templates/(:num)', 'ServiceTaskTemplateController::delete/$1', [
            'filter' => 'perm:service_task_templates.manage'
        ]);

        // Task Template items
        $routes->get('task-templates/(:num)/items', 'ServiceTaskTemplateItemController::index/$1', [
            'filter' => 'perm:service_task_template_items.view'
        ]);
        $routes->post('task-templates/(:num)/items', 'ServiceTaskTemplateItemController::create/$1', [
            'filter' => 'perm:service_task_template_items.manage'
        ]);
        $routes->put('task-template-items/(:num)', 'ServiceTaskTemplateItemController::update/$1', [
            'filter' => 'perm:service_task_template_items.manage'
        ]);
        $routes->patch('task-template-items/(:num)', 'ServiceTaskTemplateItemController::update/$1', [
            'filter' => 'perm:service_task_template_items.manage'
        ]);
        $routes->delete('task-template-items/(:num)', 'ServiceTaskTemplateItemController::delete/$1', [
            'filter' => 'perm:service_task_template_items.manage'
        ]);

        // =========================================================
        // PROJECT WORKFLOW (runtime)
        // =========================================================
        $routes->post('projects/(:num)/start-workflow', 'ProjectWorkflowController::start/$1', [
            'filter' => 'perm:project_workflows.manage'
        ]);
        $routes->get('projects/(:num)/workflow', 'ProjectWorkflowController::showByProject/$1', [
            'filter' => 'perm:project_workflows.view'
        ]);
        $routes->post('project-workflow-steps/(:num)/decision', 'ProjectWorkflowApprovalController::decision/$1', [
            'filter' => 'perm:project_workflow_approvals.manage'
        ]);
        $routes->post('project-workflow-steps/(:num)/files', 'ProjectWorkflowFileController::upload/$1', [
            'filter' => 'perm:project_workflow_files.manage'
        ]);
        $routes->get('project-workflow-steps/(:num)/files', 'ProjectWorkflowFileController::index/$1', [
            'filter' => 'perm:project_workflows.view'
        ]);
        $routes->delete('project-workflow-files/(:num)', 'ProjectWorkflowFileController::delete/$1', [
            'filter' => 'perm:project_workflow_files.manage'
        ]);
        $routes->get('projects/(:num)/workflow-summary', 'ProjectWorkflowController::summary/$1', [
            'filter' => 'perm:project_workflows.view'
        ]);

        // ---------------------------------
        // PROJECTS (ONLY ONE GROUP)
        // ---------------------------------
        $routes->group('projects', function($routes){

            // view
            $routes->get('/', 'ProjectController::index', ['filter' => 'perm:projects.view']);
            $routes->get('(:num)', 'ProjectController::show/$1', ['filter' => 'perm:projects.view']);

            // manage
            $routes->post('/', 'ProjectController::create', ['filter' => 'perm:projects.create']);
            $routes->put('(:num)', 'ProjectController::update/$1', ['filter' => 'perm:projects.update']);
            $routes->delete('(:num)', 'ProjectController::delete/$1', ['filter' => 'perm:projects.delete']);

            // ✅ FIXED: no "projects/" prefix inside group
            $routes->post('(:num)/documents', 'ProjectController::saveDocuments/$1', [
                'filter' => 'perm:projects.update'
            ]);

            // Service data page-2 (dynamic fields + generate tasks)
            $routes->get('(:num)/service-data', 'ProjectServiceDataController::index/$1', [
                'filter' => 'perm:projects.view'
            ]);
            $routes->post('(:num)/service-data', 'ProjectServiceDataController::save/$1', [
                'filter' => 'perm:projects.update'
            ]);
        });

        // ---------------------------------
        // CLIENTS
        // ---------------------------------
        $routes->group('clients', function($routes){

            $routes->get('datatables', 'ClientController::datatables', [
                'filter' => 'perm:clients.view'
            ]);

            $routes->get('/', 'ClientController::index', ['filter' => 'perm:clients.view']);
            $routes->get('(:num)', 'ClientController::show/$1', ['filter' => 'perm:clients.view']);

            $routes->post('/', 'ClientController::create', ['filter' => 'perm:clients.create']);

            $routes->put('(:num)', 'ClientController::update/$1', ['filter' => 'perm:clients.update']);
            $routes->patch('(:num)', 'ClientController::update/$1', ['filter' => 'perm:clients.update']);

            $routes->delete('(:num)', 'ClientController::delete/$1', ['filter' => 'perm:clients.delete']);
        });

        // ---------------------------------
        // LEADS
        // ---------------------------------
        $routes->group('leads', function($routes){
            $routes->get('datatables', 'LeadController::datatables', ['filter' => 'perm:leads.view']);
            $routes->get('/', 'LeadController::index', ['filter' => 'perm:leads.view']);
            $routes->get('(:num)', 'LeadController::show/$1', ['filter' => 'perm:leads.view']);
            $routes->post('/', 'LeadController::create', ['filter' => 'perm:leads.create']);
            $routes->put('(:num)', 'LeadController::update/$1', ['filter' => 'perm:leads.update']);
            $routes->patch('(:num)', 'LeadController::update/$1', ['filter' => 'perm:leads.update']);
            $routes->delete('(:num)', 'LeadController::delete/$1', ['filter' => 'perm:leads.delete']);
            $routes->post('(:num)/convert', 'LeadController::convert/$1', ['filter' => 'perm:leads.convert']);
        });

        $routes->group('lead-followups', function($routes){
            $routes->get('lead/(:num)', 'LeadFollowupController::index/$1', ['filter' => 'perm:lead_followups.view']);
            $routes->post('lead/(:num)', 'LeadFollowupController::create/$1', ['filter' => 'perm:lead_followups.create']);
            $routes->put('(:num)', 'LeadFollowupController::update/$1', ['filter' => 'perm:lead_followups.update']);
            $routes->patch('(:num)', 'LeadFollowupController::update/$1', ['filter' => 'perm:lead_followups.update']);
            $routes->delete('(:num)', 'LeadFollowupController::delete/$1', ['filter' => 'perm:lead_followups.delete']);
        });

        // ---------------------------------
        // USERS
        // ---------------------------------
      $routes->group('users', function($routes){
			$routes->get('/', 'UserController::index', ['filter' => 'perm:users.view']);
			$routes->get('dropdown', 'UserController::dropdown', ['filter' => 'perm:users.view']);
			$routes->get('roles-dropdown', 'UserController::rolesDropdown', ['filter' => 'perm:users.view']);
			$routes->get('(:num)', 'UserController::show/$1', ['filter' => 'perm:users.view']);

			$routes->post('/', 'UserController::create', ['filter' => 'perm:users.manage']);
			$routes->put('(:num)', 'UserController::update/$1', ['filter' => 'perm:users.manage']);
			$routes->patch('(:num)', 'UserController::update/$1', ['filter' => 'perm:users.manage']);
			$routes->delete('(:num)', 'UserController::delete/$1', ['filter' => 'perm:users.manage']);
		});

        // ---------------------------------
        // QUOTATIONS
        // ---------------------------------
        $routes->group('quotations', function($routes){
            $routes->get('/',        'QuotationController::index',   ['filter' => 'perm:quotations.view']);
            $routes->get('(:num)',   'QuotationController::show/$1', ['filter' => 'perm:quotations.view']);
            $routes->post('/',       'QuotationController::create',  ['filter' => 'perm:quotations.create']);
            $routes->put('(:num)',   'QuotationController::update/$1', ['filter' => 'perm:quotations.update']);
            $routes->patch('(:num)', 'QuotationController::update/$1', ['filter' => 'perm:quotations.update']);
            $routes->delete('(:num)','QuotationController::delete/$1', ['filter' => 'perm:quotations.delete']);
        });
		
		// Project Tasks
		$routes->get('projects/(:num)/tasks', 'ProjectTaskController::index/$1', [
			'filter' => 'perm:projects.view'
		]);
		$routes->post('projects/(:num)/tasks', 'ProjectTaskController::create/$1', [
			'filter' => 'perm:projects.update'
		]);
		$routes->post('projects/(:num)/generate-tasks', 'ProjectTaskController::generateFromTemplate/$1', [
			'filter' => 'perm:projects.update'
		]);

		$routes->put('project-tasks/(:num)', 'ProjectTaskController::update/$1', [
			'filter' => 'perm:projects.update'
		]);
		$routes->patch('project-tasks/(:num)', 'ProjectTaskController::update/$1', [
			'filter' => 'perm:projects.update'
		]);
		$routes->delete('project-tasks/(:num)', 'ProjectTaskController::delete/$1', [
			'filter' => 'perm:projects.update'
		]);
		
		// view single task (for View Details / Update modal prefill)
		$routes->get('project-tasks/(:num)', 'ProjectTaskController::show/$1', [
			'filter' => 'perm:projects.view'
		]);

		// status update shortcut (Mark In Progress / Mark as Done / Cancel Task)
		$routes->post('project-tasks/(:num)/status', 'ProjectTaskController::updateStatus/$1', [
			'filter' => 'perm:projects.update'
		]);

		// assignees dropdown (users table)
		$routes->get('projects/(:num)/task-assignees', 'ProjectTaskController::assignees/$1', [
			'filter' => 'perm:projects.view'
		]);
		
		// ---------------------------------
		// PROJECT INVOICES
		// ---------------------------------
		$routes->get('projects/(:num)/invoices', 'ProjectInvoiceController::index/$1', [
			'filter' => 'perm:projects.view'
		]);

		$routes->post('projects/(:num)/invoices', 'ProjectInvoiceController::create/$1', [
			'filter' => 'perm:projects.update'
		]);
		
		$routes->get('project-invoices/(:num)', 'ProjectInvoiceController::show/$1', [
			'filter' => 'perm:projects.view'
		]);

		$routes->put('project-invoices/(:num)', 'ProjectInvoiceController::update/$1', [
			'filter' => 'perm:projects.update'
		]);
		$routes->patch('project-invoices/(:num)', 'ProjectInvoiceController::update/$1', [
			'filter' => 'perm:projects.update'
		]);

		$routes->delete('project-invoices/(:num)', 'ProjectInvoiceController::delete/$1', [
			'filter' => 'perm:projects.update'
		]);
		
		$routes->post('project-invoices/(:num)/status', 'ProjectInvoiceController::updateStatus/$1', [
			'filter' => 'perm:projects.update'
		]);
		
		$routes->get('project-invoices/(:num)/payments', 'ProjectInvoicePaymentController::index/$1', [
			'filter' => 'perm:projects.view'
		]);
		$routes->post('project-invoices/(:num)/payments', 'ProjectInvoicePaymentController::create/$1', [
			'filter' => 'perm:projects.update'
		]);
		$routes->delete('project-invoice-payments/(:num)', 'ProjectInvoicePaymentController::delete/$1', [
			'filter' => 'perm:projects.update'
		]);
		
		

		// ---------------------------------
// PROJECT EXPENSES
// ---------------------------------
$routes->get('projects/(:num)/expenses', 'ProjectExpenseController::index/$1', [
    'filter' => 'perm:projects.view'
]);

$routes->post('projects/(:num)/expenses', 'ProjectExpenseController::create/$1', [
    'filter' => 'perm:projects.update'
]);

$routes->get('project-expenses/(:num)', 'ProjectExpenseController::show/$1', [
    'filter' => 'perm:projects.view'
]);

$routes->put('project-expenses/(:num)', 'ProjectExpenseController::update/$1', [
    'filter' => 'perm:projects.update'
]);

$routes->patch('project-expenses/(:num)', 'ProjectExpenseController::update/$1', [
    'filter' => 'perm:projects.update'
]);

$routes->delete('project-expenses/(:num)', 'ProjectExpenseController::delete/$1', [
    'filter' => 'perm:projects.update'
]);

// workflow
$routes->post('project-expenses/(:num)/submit', 'ProjectExpenseController::submit/$1', [
    'filter' => 'perm:projects.update'
]);

$routes->post('project-expenses/(:num)/approve', 'ProjectExpenseController::approve/$1', [
    'filter' => 'perm:projects.update'
]);

$routes->post('project-expenses/(:num)/reject', 'ProjectExpenseController::reject/$1', [
    'filter' => 'perm:projects.update'
]);

// approval history
$routes->get('project-expenses/(:num)/approvals', 'ProjectExpenseController::approvals/$1', [
    'filter' => 'perm:projects.view'
]);


// ---------------------------------
// EXPENSE CATEGORIES (MASTER)
// ---------------------------------
$routes->group('expense-categories', function($routes){

    $routes->get('/', 'ExpenseCategoryController::index', [
        'filter' => 'perm:expense_categories.view'
    ]);

    // optional: dropdown for select2
    $routes->get('dropdown', 'ExpenseCategoryController::dropdown', [
        'filter' => 'perm:expense_categories.view'
    ]);

    $routes->get('(:num)', 'ExpenseCategoryController::show/$1', [
        'filter' => 'perm:expense_categories.view'
    ]);

    $routes->post('/', 'ExpenseCategoryController::create', [
        'filter' => 'perm:expense_categories.manage'
    ]);

    $routes->put('(:num)', 'ExpenseCategoryController::update/$1', [
        'filter' => 'perm:expense_categories.manage'
    ]);

    $routes->patch('(:num)', 'ExpenseCategoryController::update/$1', [
        'filter' => 'perm:expense_categories.manage'
    ]);

    $routes->delete('(:num)', 'ExpenseCategoryController::delete/$1', [
        'filter' => 'perm:expense_categories.manage'
    ]);
});

// ---------------------------------
// ATTENDANCE
// ---------------------------------
$routes->group('attendance', function($routes) {

    $routes->get('/', 'AttendanceController::index', ['filter' => 'perm:attendance.view']);
    $routes->get('(:num)', 'AttendanceController::show/$1', ['filter' => 'perm:attendance.view']);
    $routes->post('/', 'AttendanceController::create', ['filter' => 'perm:attendance.manage']);
    $routes->put('(:num)', 'AttendanceController::update/$1', ['filter' => 'perm:attendance.manage']);
    $routes->patch('(:num)', 'AttendanceController::update/$1', ['filter' => 'perm:attendance.manage']);
    $routes->delete('(:num)', 'AttendanceController::delete/$1', ['filter' => 'perm:attendance.manage']);
});

// ---------------------------------
// EMPLOYEES
// ---------------------------------
$routes->group('employees', function($routes){

    // view
    $routes->get('/', 'EmployeeController::index', ['filter' => 'perm:employees.view']);
    $routes->get('(:num)', 'EmployeeController::show/$1', ['filter' => 'perm:employees.view']);

    // manage
    $routes->post('/', 'EmployeeController::create', ['filter' => 'perm:employees.manage']);
    $routes->put('(:num)', 'EmployeeController::update/$1', ['filter' => 'perm:employees.manage']);
    $routes->patch('(:num)', 'EmployeeController::update/$1', ['filter' => 'perm:employees.manage']);
    $routes->delete('(:num)', 'EmployeeController::delete/$1', ['filter' => 'perm:employees.manage']);
});

// ---------------------------------
// LEAVE REQUESTS
// ---------------------------------
$routes->group('leave-requests', function($routes){

    // -------------------------
    // EMPLOYEE (SELF)
    // -------------------------

    // view own leave
    $routes->get('my', 'LeaveRequestController::myLeaves', [
        'filter' => 'perm:leave_requests.view_self'
    ]);

    // apply leave
    $routes->post('/', 'LeaveRequestController::create', [
        'filter' => 'perm:leave_requests.create'
    ]);

    // edit own leave
    $routes->put('(:num)', 'LeaveRequestController::update/$1', [
        'filter' => 'perm:leave_requests.edit_self'
    ]);
    $routes->patch('(:num)', 'LeaveRequestController::update/$1', [
        'filter' => 'perm:leave_requests.edit_self'
    ]);

    // cancel own leave
    $routes->delete('(:num)', 'LeaveRequestController::cancel/$1', [
        'filter' => 'perm:leave_requests.cancel_self'
    ]);


    // -------------------------
    // ADMIN / HR
    // -------------------------

    // view all leave requests
    $routes->get('/', 'LeaveRequestController::index', [
        'filter' => 'perm:leave_requests.view'
    ]);

    $routes->get('(:num)', 'LeaveRequestController::show/$1', [
        'filter' => 'perm:leave_requests.view'
    ]);

    // approve / reject
    $routes->post('(:num)/approve', 'LeaveRequestController::approve/$1', [
        'filter' => 'perm:leave_requests.approve'
    ]);

    $routes->post('(:num)/reject', 'LeaveRequestController::reject/$1', [
        'filter' => 'perm:leave_requests.reject'
    ]);
});

// ---------------------------------
// HOLIDAYS
// ---------------------------------
$routes->group('holidays', function($routes){

    // view
    $routes->get('/', 'HolidayController::index', ['filter' => 'perm:holidays.view']);
    $routes->get('(:num)', 'HolidayController::show/$1', ['filter' => 'perm:holidays.view']);

    // manage
    $routes->post('/', 'HolidayController::create', ['filter' => 'perm:holidays.manage']);
    $routes->put('(:num)', 'HolidayController::update/$1', ['filter' => 'perm:holidays.manage']);
    $routes->patch('(:num)', 'HolidayController::update/$1', ['filter' => 'perm:holidays.manage']);
    $routes->delete('(:num)', 'HolidayController::delete/$1', ['filter' => 'perm:holidays.manage']);
});

// ---------------------------------
// PERMISSIONS / ROLE PERMISSIONS
// ---------------------------------

// all permission masters
$routes->get('permissions', 'RolePermissionControllerApi::permissionList', [
    'filter' => 'perm:permissions.manage'
]);

$routes->get('permissions/grouped', 'RolePermissionControllerApi::permissionGrouped', [
    'filter' => 'perm:permissions.manage'
]);

// get one role's permissions
$routes->get('roles/(:num)/permissions', 'RolePermissionControllerApi::rolePermissions/$1', [
    'filter' => 'perm:permissions.manage'
]);

// replace full permission set for a role
$routes->post('roles/(:num)/permissions', 'RolePermissionControllerApi::syncRolePermissions/$1', [
    'filter' => 'perm:permissions.manage'
]);

// single mapping CRUD
$routes->post('role-permissions', 'RolePermissionControllerApi::create', [
    'filter' => 'perm:permissions.manage'
]);

$routes->put('role-permissions/(:num)', 'RolePermissionControllerApi::update/$1', [
    'filter' => 'perm:permissions.manage'
]);

$routes->patch('role-permissions/(:num)', 'RolePermissionControllerApi::update/$1', [
    'filter' => 'perm:permissions.manage'
]);

$routes->delete('role-permissions/(:num)', 'RolePermissionControllerApi::delete/$1', [
    'filter' => 'perm:permissions.manage'
]);

// ---------------------------------
// REPORTS
// ---------------------------------
$routes->group('reports', function($routes){

    // Attendance Monthly Report
    $routes->get('attendance-monthly', 'ReportController::attendanceMonthly', [
        'filter' => 'perm:reports.attendance'
    ]);

    // Project Productivity Report
    $routes->get('project-productivity', 'ReportController::projectProductivity', [
        'filter' => 'perm:reports.project_productivity'
    ]);

    // Employee Performance Report
    $routes->get('employee-performance', 'ReportController::employeePerformance', [
        'filter' => 'perm:reports.employee_performance'
    ]);
	
	// Staff Based Task Summary
	$routes->get('staff-task-summary', 'ReportController::staffTaskSummary', [
		'filter' => 'perm:reports.employee_performance'
	]);

	// Staff Based Task Detail List
	$routes->get('staff-tasks/(:num)', 'ReportController::staffTasks/$1', [
		'filter' => 'perm:reports.employee_performance'
	]);
	
	$routes->get('my-tasks', 'ReportController::myTasks', [
		'filter' => 'perm:reports.my_tasks'
	]);
	
	// Client Report
	$routes->get('client-report', 'ReportController::clientReport', [
		'filter' => 'perm:reports.client_report'
	]);

});

// ---------------------------------
// TEAMS
// ---------------------------------
$routes->group('teams', function($routes){
    $routes->get('/', 'TeamController::index');
    $routes->get('dropdown', 'TeamController::dropdown');
    $routes->get('dashboard', 'TeamController::dashboard');
    $routes->get('(:num)', 'TeamController::show/$1');
    $routes->post('/', 'TeamController::create');
    $routes->put('(:num)', 'TeamController::update/$1');
    $routes->patch('(:num)', 'TeamController::update/$1');
    $routes->delete('(:num)', 'TeamController::delete/$1');
    $routes->patch('(:num)/toggle-status', 'TeamController::toggleStatus/$1');
    $routes->get('(:num)/members', 'TeamController::members/$1');
    $routes->post('(:num)/members', 'TeamController::addMember/$1');
    $routes->delete('(:num)/members/(:num)', 'TeamController::removeMember/$1/$2');
});

// ---------------------------------
// TASK SUBTASKS
// ---------------------------------
$routes->get('project-tasks/(:num)/subtasks', 'TaskSubtaskController::index/$1');
$routes->post('project-tasks/(:num)/subtasks', 'TaskSubtaskController::create/$1');
$routes->post('project-tasks/(:num)/subtasks/bulk', 'TaskSubtaskController::bulkCreate/$1');
$routes->get('subtasks/(:num)', 'TaskSubtaskController::show/$1');
$routes->put('subtasks/(:num)', 'TaskSubtaskController::update/$1');
$routes->patch('subtasks/(:num)', 'TaskSubtaskController::update/$1');
$routes->delete('subtasks/(:num)', 'TaskSubtaskController::delete/$1');

});

});
// WhatsApp Webhook & Dashboard Routes (Put at the very bottom of the file)
$routes->match(['get', 'post'], 'whatsapp/webhook', '\App\Controllers\WhatsappWebhookController::index');
$routes->get('whatsapp/dashboard', '\App\Controllers\WhatsappWebhookController::dashboard');

