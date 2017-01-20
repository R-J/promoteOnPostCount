<?php
$PluginInfo['promoteOnPostCount'] = [
    'Name' => 'Promote After Moderated',
    'Description' => 'Allows automatic role changing after a given number of post counts have been approved.',
    'Version' => '0.1',
    'RequiredApplications' => ['Vanilla' => '2.3'],
    'MobileFriendly' => true,
    'HasLocale' => true,
    'Author' => 'Robin Jurinka',
    'AuthorUrl' => 'https://vanillaforums.org/profile/R_J',
    'SettingsUrl' => '/settings/promoteonpostcount',
    'License' => 'MIT'
];
class PromoteOnPostCountPlugin extends Gdn_Plugin {
    /**
     * Pre-fill settings with sane settings.
     *
     * @return void.
     */
    public function setup() {
        touchConfig(
            'promoteOnPostCount.ToRoleID',
            RoleModel::getDefaultRoles(RoleModel::TYPE_MEMBER)
        );
        touchConfig('Preferences.Popup.RolePromotion', 1);
        $this->structure();
    }

    /**
     * Create activity type for role promotion.
     *
     * @return void.
     */
    public function structure() {
        $activityModel = new ActivityModel();
        $activityModel->defineType(
            'RolePromotion',
            [
                'Notify' => 1,
                'Public' => 0
            ]
        );
    }

    /**
     * Notify user of his role promotion.
     *
     * @param Integer $userID ID of the user to notify.
     * @param Integer $roleID ID of the role the user has been assigned.
     *
     * @return void.
     */
    private function roleChangeActivity($userID, $roleID) {
        $activityModel = new ActivityModel();
        $activityModel->queue(
            [
                'ActivityType' => 'RolePromotion',
                'ActivityUserID' => $userID,
                'RegardingUserID' => $userID,
                'NotifyUserID' => $userID,
                'HeadlineFormat' => t('{NotifyUserID,You} have been promoted.'),
                'Story' => t('Your posts do no longer require moderation.')
            ],
            'RolePromotion',
            ['Force' => true]
        );
        $activityModel->saveQueue();
    }

    /**
     * Settings page.
     *
     * @param SettingsController $sender Instance of the calling class.
     *
     * @return void.
     */
    public function settingsController_promoteOnPostCount_create($sender) {
        $sender->permission('Garden.Settings.Manage');

        $sender->addSideMenu('dashboard/settings/plugins');

        $sender->setData('Title', t('Promotion Rule'));

        // Get role names.
        $roleModel = new RoleModel();
        $roles = $roleModel->getArray();
        // Filter out admins and mods (too dangerous).
        $adminRoles = $roleModel->getbyType($roleModel::TYPE_ADMINISTRATOR);
        foreach ($adminRoles as $role) {
            unset($roles[$role->RoleID]);
        }
        $modRoles = $roleModel->getbyType($roleModel::TYPE_MODERATOR);
        foreach ($modRoles as $role) {
            unset($roles[$role->RoleID]);
        }
        $sender->setData('AvailableRoles', $roles);

        // Prepare form fields.
        $validation = new Gdn_Validation();
        $configurationModel = new Gdn_ConfigurationModel($validation);
        $configurationModel->setField(
            [
                'promoteOnPostCount.MinComments',
                'promoteOnPostCount.MinDiscussions',
                'promoteOnPostCount.MinPosts',
                'promoteOnPostCount.FromRoleID',
                'promoteOnPostCount.ToRoleID'
            ]
        );
        $sender->Form->setModel($configurationModel);

        if ($sender->Form->authenticatedPostBack() === false) {
            // If form is displayed "unposted".
            $sender->Form->setData($configurationModel->Data);
        } else {
            // Validate posted form.
            $sender->Form->validateRule('promoteOnPostCount.ToRoleID', 'ValidateRequired');
            $sender->Form->validateRule('promoteOnPostCount.ToRoleID', 'ValidateInteger');
            $sender->Form->validateRule('promoteOnPostCount.FromRoleID', 'ValidateRequired');
            $sender->Form->validateRule('promoteOnPostCount.FromRoleID', 'ValidateInteger');
            $sender->Form->validateRule('promoteOnPostCount.MinComments', 'ValidateRequired');
            $sender->Form->validateRule('promoteOnPostCount.MinComments', 'ValidateInteger');
            $sender->Form->validateRule('promoteOnPostCount.MinDiscussions', 'ValidateRequired');
            $sender->Form->validateRule('promoteOnPostCount.MinDiscussions', 'ValidateInteger');
            $sender->Form->validateRule('promoteOnPostCount.MinPosts', 'ValidateRequired');
            $sender->Form->validateRule('promoteOnPostCount.MinPosts', 'ValidateInteger');

            // Check if either comment/discussion or post is set, but not both.
            if ($sender->Form->getValue('promoteOnPostCount.MinPosts', 0) != 0) {
                if (
                    $sender->Form->getValue('promoteOnPostCount.MinComments', 0) +
                    $sender->Form->getValue('promoteOnPostCount.MinDiscussions', 0) > 0
                ) {
                    $sender->Form->addError('Please set either min. comment/discussion count or post count, but not both.');
                }
            }

            // Ensure that new role doesn't need moderation.
            $roleModel = new RoleModel();
            $permissions = $roleModel->getPermissions($sender->Form->getValue('promoteOnPostCount.ToRoleID'));
            if ($permissions[0]['Vanilla.Approval.Require'] == true) {
                $sender->Form->addError('This role hasn\'t permission to post unmoderated. Choosing this role doesn\'t make sense', 'promoteOnPostCount.ToRoleID');
            }
            // Try saving values.
            if ($sender->Form->save() !== false) {
                $sender->informMessage(
                    sprite('Check', 'InformSprite').t('Your settings have been saved.'),
                    ['CssClass' => 'Dismissable AutoDismiss HasSprite']
                );
            }
        }
        $sender->render($this->getView('settings.php'));
    }

    /**
     * Check if log entry is pending post and level up user.
     *
     * @param LogModel $sender Instance of the calling class.
     * @param mixed    $args   Event arguments.
     *
     * @return void.
     */
    public function logModel_afterRestore_handler($sender, $args) {
        // Only take action for pending posts.
        if (
            $args['Log']['Operation'] != 'Pending' ||
            !in_array($args['Log']['RecordType'], ['Comment', 'Discussion'])
        ) {
            return;
        }

        // Make sure plugin is configured.
        $config = c('promoteOnPostCount');
        $minComments = c('promoteOnPostCount.MinComments', false);
        $minDiscussions = c('promoteOnPostCount.MinDiscussions', false);
        $minPosts = c('promoteOnPostCount.MinPosts', false);
        $fromRoleID = c('promoteOnPostCount.FromRoleID', false);
        $roleID = c('promoteOnPostCount.ToRoleID', false);
        // All settings must be set.
        if (
            $minComments === false ||
            $minDiscussions === false ||
            $minPosts === false ||
            $roleID === false
        ) {
            return;
        }
        // At least one Minimum must be set.
        if ($minComments +  $minDiscussions + $minPosts == 0) {
            return;
        }

        // Get the current users post counts.
        $countComments = Gdn::sql()->getCount(
            'Comment',
            ['InsertUserID' => $args['Log']['InsertUserID']]
        );
        $countDiscussions = Gdn::sql()->getCount(
            'Discussion',
            ['InsertUserID' => $args['Log']['InsertUserID']]
        );

        // Check if either comment and discussion count is reached
        // or post count is reached.
        if (
            !($countComments >= $minComments && $countDiscussions >= $minDiscussions) &&
            !($countComments + $countDiscussions >= $minPosts)
        ) {
            return;
        }

        // Get all current roles.
        $currentRoles = Gdn::userModel()->getRoles(
            $args['Log']['InsertUserID']
        )->resultArray();
        $newRoles = array_column($currentRoles, 'RoleID');

        // Ensure user has FromRoleID.
        if (!in_array($fromRoleID, $newRoles)) {
            return;
        }
        // Remove old role.
        $newRoles = array_diff($newRoles, [$fromRoleID]);

        // Add the new role.
        $newRoles[] = $roleID;

        // Level up!
        Gdn::userModel()->saveRoles(
            $args['Log']['InsertUserID'],
            $newRoles,
            true
        );
        $this->roleChangeActivity($args['Log']['InsertUserID'], $roleID);

        // Give feedback to admin.
        $user = Gdn::userModel()->getID($userID);
        Gdn::controller()->informMessage(sprintf(t('%1$s has been promoted and his/her posts will no longer need moderation'), $user->Name));
    }
}
