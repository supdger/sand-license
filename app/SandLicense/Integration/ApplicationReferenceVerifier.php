<?php

declare(strict_types=1);

namespace app\SandLicense\Integration;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\sandadmin\exception\ApiException;

/** Read-only consumer of SandIAM's existing control-plane authorization port. */
final class ApplicationReferenceVerifier
{
    public static function assertApplication(string $applicationId, string $organizationId, array $adminScope): void
    {
        $application = filter_var($applicationId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $organization = filter_var($organizationId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $actor = filter_var($adminScope['actor_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($application) || !is_int($organization) || !is_int($actor)
            || !is_array($adminScope['admin_info'] ?? null)
            || !class_exists(AdminOrganizationAccess::class)
            || !method_exists(AdminOrganizationAccess::class, 'assertApplication')
            || !class_exists(Application::class)) {
            self::deny();
        }
        try {
            (new AdminOrganizationAccess($actor, $adminScope['admin_info']))->assertApplication($application);
            // Public IAM model supplies ownership; assertApplication above decides access.
            $record = Application::where('id', $application)->where('status', 1)->find();
            if ($record === null || (int) $record->organization_id !== $organization) self::deny();
        } catch (\Throwable) {
            self::deny();
        }
    }

    private static function deny(): never
    {
        throw new ApiException('SAND_LICENSE_APPLICATION_REFERENCE_DENIED: 接入应用不存在、归属不一致或当前管理员无权使用', 401);
    }
}
