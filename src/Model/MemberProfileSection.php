<?php

namespace Symbiote\MemberProfiles\Model;

use Symbiote\MemberProfiles\Pages\MemberProfilePage;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Security\Permission;
use Exception;

/**
 * A section of a public profile page.
 *
 * @package    silverstripe-memberprofiles
 * @subpackage dataobjects
 * @property int $ParentID
 * @property string $CustomTitle
 * @method MemberProfilePage Parent()
 */
class MemberProfileSection extends DataObject
{
    private static string $table_name = 'MemberProfileSection';

    private static array $db = [
        'CustomTitle' => 'Varchar(100)'
    ];

    private static array $has_one = [
        'Parent' => MemberProfilePage::class
    ];

    private static array $owned_by = [
        'Parent',
    ];

    private static array $extensions = [
        Versioned::class . "('Stage', 'Live')"
    ];

    private static array $summary_fields = [
        'DefaultTitle' => 'Title',
        'CustomTitle'  => 'Custom Title'
    ];

    /**
     * @var Member
     */
    private $member;

    /**
     * @return Member
     */
    public function getMember()
    {
        return $this->member;
    }

    /**
     * @param Member $member
     */
    public function setMember($member): void
    {
        $this->member = $member;
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $fields->addFieldsToTab(
            'Root.Main',
            array(
                ReadonlyField::create('DefaultTitle', _t('MemberProfiles.SECTIONTYPE', 'Section type')),
                HiddenField::create('ClassName', '')
            ),
            'CustomTitle'
        );

        return $fields;
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->CustomTitle ? $this->CustomTitle : $this->getDefaultTitle();
    }

    /**
     * Returns the title for this profile section. You must implement this in
     * subclasses.
     */
    public function getDefaultTitle(): never
    {
        throw new Exception(sprintf('Please implement getDefaultTitle() on {get_class(%s)}.', $this));
    }

    /**
     * Controls whether the title is shown in the template.
     */
    public function ShowTitle(): bool
    {
        return true;
    }

    /**
     * Returns the content to be rendered into the profile template.
     */
    public function forTemplate(): string
    {
        throw new Exception(sprintf('Please implement forTemplate() on {get_class(%s)}.', $this));
    }

    public function canEdit($member = null)
    {
        return $this->customExtendedCan(__FUNCTION__, $member);
    }

    public function canView($member = null)
    {
        return $this->customExtendedCan(__FUNCTION__, $member);
    }

    public function canCreate($member = null, $context = array())
    {
        return $this->customExtendedCan(__FUNCTION__, $member, $context);
    }

    public function canDelete($member = null)
    {
        return $this->customExtendedCan(__FUNCTION__, $member);
    }

    /**
     * @return bool|null
     */
    private function customExtendedCan(string $methodName, $member, $context = array())
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        // Standard mechanism for accepting permission changes from extensions
        $extended = $this->extendedCan($methodName, $member, $context);
        if ($extended !== null) {
            return $extended;
        }

        // If has permission to edit profile page, you have permission to edit this field.
        $page = $this->Parent();
        if ($page &&
            $page->exists()) {
            return $page->$methodName($member);
        }

        // Default permissions
        if (Permission::checkMember($member, "SITETREE_EDIT_ALL")) {
            return true;
        }

        // Fallback to default DataObject permissions
        return parent::$methodName($member);
    }
}
