<?php

namespace Symbiote\MemberProfiles\Model;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\SelectField;
use Symbiote\MemberProfiles\Pages\MemberProfilePage;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\TextField;
use SilverStripe\Security\Member;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use SilverStripe\Security\Permission;

/**
 * @package silverstripe-memberprofiles
 * @property string $ProfileVisibility
 * @property string $RegistrationVisibility
 * @property bool $MemberListVisible
 * @property string $PublicVisibility
 * @property bool $PublicVisibilityDefault
 * @property string $MemberField
 * @property string $CustomTitle
 * @property string $DefaultValue
 * @property string $Note
 * @property string $CustomError
 * @property bool $Unique
 * @property bool $Required
 * @property int $Sort
 * @method MemberProfilePage ProfilePage()
 */
class MemberProfileField extends DataObject
{
    private static string $table_name = 'MemberProfileField';

    private static array $db = [
        'ProfileVisibility'       => 'Enum("Edit, Readonly, Hidden", "Hidden")',
        'RegistrationVisibility'  => 'Enum("Edit, Readonly, Hidden", "Hidden")',
        'MemberListVisible'       => 'Boolean',
        'PublicVisibility'        => 'Enum("Display, MemberChoice, Hidden", "Hidden")',
        'PublicVisibilityDefault' => 'Boolean',
        'MemberField'             => 'Varchar(100)',
        'CustomTitle'             => 'Varchar(100)',
        'DefaultValue'            => 'Text',
        'Note'                    => 'Varchar(255)',
        'CustomError'             => 'Varchar(255)',
        'Unique'                  => 'Boolean',
        'Required'                => 'Boolean',
        'Sort'                    => 'Int'
    ];

    private static array $has_one = [
        'ProfilePage' => MemberProfilePage::class
    ];

    private static array $owned_by = [
        'ProfilePage',
    ];

    private static array $extensions = [
        Versioned::class . "('Stage', 'Live')"
    ];

    private static array $summary_fields = [
        'DefaultTitle'           => 'Field',
        'ProfileVisibility'      => 'Profile Visibility',
        'RegistrationVisibility' => 'Registration Visibility',
        'CustomTitle'            => 'Custom Title',
        'Unique'                 => 'Unique',
        'Required'               => 'Required'
    ];

    private static string $default_sort = 'Sort';

    /**
     * Temporary local cache of form fields - otherwise we can potentially be calling
     * getMemberFormFields 20 - 30 times per request via getDefaultTitle.
     *
     * It's declared as a static so all instances have access to it after it's
     * loaded the first time.
     *
     * @var FieldList
     */
    protected static $member_fields;

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $memberFields = $this->getMemberFields();
        $memberField = $memberFields->dataFieldByName($this->MemberField);

        $fields->removeByName('MemberField');
        $fields->removeByName('ProfilePageID');
        $fields->removeByName('Sort');

        /**
         * @var CompositeField|null $tab
         */
        $tab = $fields->fieldByName('Root.Main');
        if ($tab) {
            $tab->getChildren()->changeFieldOrder(array(
                'CustomTitle',
                'DefaultValue',
                'Note',
                'ProfileVisibility',
                'RegistrationVisibility',
                'MemberListVisible',
                'PublicVisibility',
                'PublicVisibilityDefault',
                'CustomError',
                'Unique',
                'Required'
            ));
        }

        $fields->unshift(ReadonlyField::create('MemberField', _t('MemberProfiles.MEMBERFIELD', 'Member Field')));

        $fields->insertBefore(
            'ProfileVisibility',
            HeaderField::create('VisibilityHeader', _t('MemberProfiles.VISIBILITY', 'Visibility'))
        );

        $fields->insertBefore(
            'CustomError',
            HeaderField::create('ValidationHeader', _t('MemberProfiles.VALIDATION', 'Validation'))
        );

        if ($memberField instanceof DropdownField) {
            $fields->replaceField('DefaultValue', $default = DropdownField::create('DefaultValue', _t('MemberProfiles.DEFAULTVALUE', 'Default Value'), $memberField->getSource()));
            $default->setEmptyString(' ');
        } elseif ($memberField instanceof TextField) {
            $fields->replaceField('DefaultValue', TextField::create('DefaultValue', _t('MemberProfiles.DEFAULTVALUE', 'Default Value')));
        } else {
            $fields->removeByName('DefaultValue');
        }

        /**
         * @var SelectField|null $publicVisibilityField
         */
        $publicVisibilityField = $fields->dataFieldByName('PublicVisibility');
        if ($publicVisibilityField &&
            $publicVisibilityField->hasMethod('setSource')) {
            $publicVisibilityField->setSource(array(
                'Display'      => _t('MemberProfiles.ALWAYSDISPLAY', 'Always display'),
                'MemberChoice' => _t('MemberProfiles.MEMBERCHOICE', 'Allow the member to choose'),
                'Hidden'       => _t('MemberProfiles.DONTDISPLAY', 'Do not display')
            ));
        }

        $fields->dataFieldByName('PublicVisibilityDefault')->setTitle(_t(
            'MemberProfiles.DEFAULTPUBLIC',
            'Mark as public by default?'
        ));

        $fields->dataFieldByName('MemberListVisible')->setTitle(_t(
            'MemberProfiles.VISIBLEMEMLISTINGPAGE',
            'Visible on the member listing page?'
        ));

        if ($this->isNeverPublic()) {
            $fields->makeFieldReadonly('MemberListVisible');
            $fields->makeFieldReadonly('PublicVisibility');
        }

        if ($this->isAlwaysUnique()) {
            $fields->makeFieldReadonly('Unique');
        }

        if ($this->isAlwaysRequired()) {
            $fields->makeFieldReadonly('Required');
        }

        $this->extend('updateMemberProfileCMSFields', $fields);

        return $fields;
    }

    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if (!$this->Sort) {
            $this->Sort = MemberProfileField::get()->max('Sort') + 1;
        }
    }


    /**
     * @uses   MemberProfileField::getDefaultTitle
     * @return string
     */
    public function getTitle()
    {
        if ($this->CustomTitle) {
            return $this->CustomTitle;
        }

        return $this->getDefaultTitle(false);
    }

    /**
     * Get the default title for this field from the form field.
     *
     * @param bool $force Force a non-empty title to be returned.
     * @return string
     */
    public function getDefaultTitle($force = true)
    {
        $fields = $this->getMemberFields();
        $field  = $fields->dataFieldByName($this->MemberField);
        $title  = $field->Title();

        if (!$title && $force) {
            return $field->getName();
        }

        return $title;
    }

    /**
     * @return FieldList
     */
    protected function getMemberFields()
    {
        if (!self::$member_fields) {
            self::$member_fields = singleton(Member::class)->getMemberFormFields();
        }

        return self::$member_fields;
    }

    public function isAlwaysRequired(): bool
    {
        return in_array(
            $this->MemberField,
            array(Config::inst()->get(Member::class, 'unique_identifier_field'), 'Password')
        );
    }

    public function isAlwaysUnique(): bool
    {
        return $this->MemberField == Config::inst()->get(Member::class, 'unique_identifier_field');
    }

    public function isNeverPublic(): bool
    {
        return $this->MemberField == 'Password';
    }

    public function getUnique(): bool
    {
        if ($this->getField('Unique')) {
            return true;
        }

        return $this->isAlwaysUnique();
    }

    public function getRequired(): bool
    {
        if ($this->getField('Required')) {
            return true;
        }

        return $this->isAlwaysRequired();
    }

    /**
     * @return string
     */
    public function getPublicVisibility()
    {
        if ($this->isNeverPublic()) {
            return 'Hidden';
        }

        return $this->getField('PublicVisibility');
    }

    public function getMemberListVisible(): bool
    {
        return $this->getField('MemberListVisible') && !$this->isNeverPublic();
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
        $page = $this->ProfilePage();
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
