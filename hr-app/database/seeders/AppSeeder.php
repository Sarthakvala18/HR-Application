<?php

namespace Database\Seeders;

use App\Enums\ProvisioningMode;
use App\Models\App;
use Illuminate\Database\Seeder;

/**
 * The app catalog. `onboard_instructions_md` and `offboard_instructions_md`
 * are what HR actually reads on a manual task card, so they carry the real
 * click paths from the SOP rather than a vague "create the account".
 *
 * offboard_priority: higher runs first, so access-killing steps lead.
 */
class AppSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->apps() as $app) {
            App::updateOrCreate(['key' => $app['key']], $app);
        }
    }

    private function apps(): array
    {
        return [
            [
                'key' => 'google_workspace',
                'name' => 'Google Workspace',
                'provisioning_mode' => ProvisioningMode::Manual,
                'console_url' => env('GOOGLE_ADMIN_CONSOLE_URL', 'https://admin.google.com'),
                'supports_license_tiers' => true,
                'license_tiers' => ['business_starter', 'business_standard'],
                'costs_money' => true,
                'offboard_priority' => 100,
                'onboard_instructions_md' => <<<'MD'
                    1. Open the Google Admin console.
                    2. Check whether a seat is free. If the account cannot be added, the seat limit is reached: **contact the reseller** to add a seat, then rename it.
                    3. If replacing a deleted user, **migrate the previous user's data first** (see the offboarding card for the destination).
                    4. Create the user with a temporary password and force a reset at first login.
                    5. Add the groups and shared drives listed in the role template.
                    6. Subscribe them to the **CF Holidays** calendar.
                    7. Paste the created work email below to complete this step.
                    MD,
                'offboard_instructions_md' => <<<'MD'
                    **Do this first, before anything else.** Google is the identity backbone, so suspending it cuts most access at once.

                    1. Suspend the user.
                    2. Reset their password.
                    3. Sign out all sessions and revoke OAuth tokens and app passwords.
                    4. Wipe managed mobile devices if any are enrolled.

                    Data migration and deletion happen on a later card, not this one.
                    MD,
            ],
            [
                'key' => 'bitwarden',
                'name' => 'Bitwarden (self-hosted)',
                'provisioning_mode' => ProvisioningMode::Manual,
                'console_url' => env('BITWARDEN_CONSOLE_URL', 'https://vault.internal.example'),
                'supports_license_tiers' => false,
                'costs_money' => false,
                'offboard_priority' => 95,
                'onboard_instructions_md' => <<<'MD'
                    1. Log in to the Bitwarden console and open **Admin Console → Members**.
                    2. Invite the member using their **personal** email so they can accept before day one.
                    3. Assign the collections listed in the role template.
                    4. Send them the extension setup instructions.
                    5. Wait for them to accept, then **approve** the member.
                    6. Confirm below once approved.
                    MD,
                'offboard_instructions_md' => <<<'MD'
                    1. Admin Console → Members → find the user → three dots → **Remove**.
                    2. Review the generated shared-credential rotation list: every shared login in the collections they could read should be rotated.

                    Removing the member does not rotate shared passwords. That list is the point of this step.
                    MD,
            ],
            [
                'key' => 'zoho_one',
                'name' => 'Zoho One',
                'provisioning_mode' => ProvisioningMode::Automated,
                'console_url' => env('ZOHO_ONE_CONSOLE_URL'),
                'supports_license_tiers' => true,
                'license_tiers' => ['standard'],
                'costs_money' => true,
                'offboard_priority' => 80,
                'onboard_instructions_md' => 'Automated: creates the Zoho One user and assigns the apps from the role template. A license purchase, if one is needed, is gated on an approval card first.',
                'offboard_instructions_md' => 'Automated: deactivates the user. Reducing the license count is a separate approval card (the licence approvers).',
            ],
            [
                'key' => 'zoho_desk',
                'name' => 'Zoho Desk',
                'provisioning_mode' => ProvisioningMode::Automated,
                'console_url' => 'https://desk.zoho.com/agent/<org>/all/setup#setup/users-control/agents/active',
                'supports_license_tiers' => false,
                'costs_money' => false,
                'offboard_priority' => 75,
                'onboard_instructions_md' => <<<'MD'
                    Automated: creates the agent profile with department and ticket access from the role template.

                    Two things stay manual because the API does not expose them:
                    - Creating a **new department** and its assignment rules (the Desk administrators).
                    - The Gmail-side confirmation of the support-address forwarding, if this role needs one.
                    MD,
                'offboard_instructions_md' => 'Automated: deactivates the agent and reassigns their open tickets to the manager.',
            ],
            [
                'key' => 'slack',
                'name' => 'Slack',
                'provisioning_mode' => ProvisioningMode::Semi,
                'console_url' => env('SLACK_ADMIN_CONSOLE_URL', 'https://your-workspace.slack.com/admin'),
                'supports_license_tiers' => true,
                'license_tiers' => ['member', 'multi_channel_guest', 'single_channel_guest'],
                'costs_money' => true,
                'offboard_priority' => 70,
                'onboard_instructions_md' => <<<'MD'
                    The app attempts the invite via the API first. On the Pro plan this may be refused, in which case do it by hand:

                    1. Open the Slack admin page → **Invite people**.
                    2. Use their **work Gmail** address.
                    3. Choose member, or single/multi-channel guest per the role template.
                    4. Select the channels listed in the template.
                    5. Send, then remind them to complete 2FA.
                    MD,
                'offboard_instructions_md' => <<<'MD'
                    1. Find the user in the Slack admin page → **Deactivate**.
                    2. If someone with the same first name is joining, change the old profile's email first so the address can be reused.
                    MD,
            ],
            [
                'key' => 'zoom',
                'name' => 'Zoom',
                'provisioning_mode' => ProvisioningMode::Automated,
                'console_url' => 'https://zoom.us/account/user',
                'supports_license_tiers' => true,
                'license_tiers' => ['basic', 'pro'],
                'costs_money' => true,
                'offboard_priority' => 60,
                'onboard_instructions_md' => <<<'MD'
                    Automated: creates the user at the tier in the role template and sets **location and timezone from the employee record**, which is what prevents the auto-recording problem.

                    Before requesting a new Pro license the app checks for a seat freed by a pending exit.
                    MD,
                'offboard_instructions_md' => <<<'MD'
                    Automated: deactivates the user and transfers upcoming meetings and cloud recordings to the manager.

                    **License removal stays manual** (it needs plan management and a CVV re-verification):
                    Plans and Billing → Plan Management → Zoom Workplace Pro → Manage → reduce the count → confirm.

                    If the seat is not removed before the billing cycle it will be charged again. The license guard will remind you three days before renewal.
                    MD,
            ],
            [
                'key' => 'aws_s3',
                'name' => 'AWS S3 (call recordings)',
                'provisioning_mode' => ProvisioningMode::Manual,
                'console_url' => 'https://console.aws.amazon.com/s3',
                'supports_license_tiers' => false,
                'costs_money' => false,
                'offboard_priority' => 50,
                'onboard_instructions_md' => 'Only for roles that run client calls. Coordinate with the automation owner to point the Zoom recording automation at the right repository.',
                'offboard_instructions_md' => 'Remove their access and confirm any recordings they owned are in the correct repository.',
            ],
            [
                'key' => 'n8n',
                'name' => 'n8n automation hub',
                'provisioning_mode' => ProvisioningMode::Manual,
                'console_url' => env('N8N_BASE_URL', 'https://automation.internal.example'),
                'supports_license_tiers' => false,
                'costs_money' => false,
                'offboard_priority' => 40,
                'onboard_instructions_md' => 'Tech roles only. Create the account and grant only the workflows they need.',
                'offboard_instructions_md' => 'Remove the account. If they held credentials in n8n, flag those for rotation.',
            ],
            [
                'key' => 'typeform',
                'name' => 'Typeform',
                'provisioning_mode' => ProvisioningMode::Manual,
                'console_url' => 'https://admin.typeform.com',
                'supports_license_tiers' => false,
                'costs_money' => true,
                'offboard_priority' => 30,
                'onboard_instructions_md' => 'HR and ops roles only. Add them to the workspace.',
                'offboard_instructions_md' => 'Remove them from the workspace.',
            ],
        ];
    }
}
