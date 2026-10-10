<?php

use App\Models\RolePermissionModel;

if (!function_exists('current_role_id')) {
    function current_role_id(): int
    {
        return (int) ($_SERVER['HTTP_X_ROLE_ID'] ?? 0);
    }
}

if (!function_exists('has_permission')) {
    function has_permission(string $permCode): bool
    {
        static $cache = null;

        if ($cache === null) {
            $roleId = current_role_id();
            if ($roleId <= 0) return false;

            $rp = new RolePermissionModel();
            $codes = $rp->getPermissionCodesByRoleId($roleId);
            $cache = array_flip($codes);
        }

        return isset($cache[$permCode]);
    }
}
