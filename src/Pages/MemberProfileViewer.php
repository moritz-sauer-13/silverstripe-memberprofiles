<?php

namespace Symbiote\MemberProfiles\Pages;

use SilverStripe\Model\ModelData;
use SilverStripe\Model\List\PaginatedList;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\ModelDataCustomised;
use PageController;
use SilverStripe\Security\Member;
use SilverStripe\Control\Controller;

/**
 * Handles displaying member's public profiles.
 *
 * @package    silverstripe-memberprofiles
 * @subpackage controllers
 */
class MemberProfileViewer extends PageController
{
    private static array $url_handlers = [
        ''           => 'handleList',
        '$MemberID!' => 'handleView',
    ];

    private static array $allowed_actions = [
        'handleList',
        'handleView',
    ];

    private MemberProfilePageController $parent;

    /**
     * @var string
     */
    private $name;

    /**
     * @param string $name
     */
    public function __construct(MemberProfilePageController $parent, $name)
    {
        $this->parent = $parent;
        $this->name   = $name;

        parent::__construct();
    }

    /**
     * Displays a list of all members on the site that belong to the selected
     * groups.
     */
    public function handleList($request): ModelData
    {
        $parent = $this->getParent();
        $fields  = $parent->Fields()->filter('MemberListVisible', true);

        $groups = $parent->Groups();
        if ($groups->count() > 0) {
            $members = $groups->relation('Members');
        } else {
            $members = Member::get();
            // NOTE(Jake): 2018-05-02
            //
            // We may want to enable a flag so that ADMIN users are automatically omitted from this list
            // by default.
            //
            //$members = $members->filter('ID:not', Permission::get_members_by_permission('ADMIN')->map('ID', 'ID')->toArray());
        }

        $members = PaginatedList::create($members, $request);

        $list = ArrayList::create();
        foreach ($members as $member) {
            $cols   = ArrayList::create();
            $public = $member->getPublicFields();
            $link   = $this->Link($member->ID);

            foreach ($fields as $field) {
                if ($field->PublicVisibility == 'MemberChoice'
                    && !in_array($field->MemberField, $public)
                ) {
                    $value =  null;
                } else {
                    $value = $member->{$field->MemberField};
                }

                $cols->push(ArrayData::create(array(
                    'Name'     => $field->MemberField,
                    'Title'    => $field->Title,
                    'Value'    => $value,
                    'Sortable' => $member->hasDatabaseField($field->MemberField),
                    'Link'     => $link
                )));
            }

            $list->push($member->customise(array(
                'Fields' => $cols
            )));
        }

        $list = PaginatedList::create($list, $request);
        $list->setLimitItems(false);
        $list->setTotalItems($members->getTotalItems());

        $this->data()->Title  = _t('MemberProfiles.MEMBERLIST', 'Member List');
        $this->data()->Parent = $this->getParent();

        return $this->customise(array(
            'Type'    => 'List',
            'Members' => $list
        ));
    }

    /**
     * Handles viewing an individual user's profile.
     *
     * @return ModelDataCustomised
     */
    public function handleView($request): ModelData
    {
        $id = $request->param('MemberID');

        if (!ctype_digit($id)) {
            $this->httpError(404);
        }

        /**
         * @var Member $member
         */
        $member = Member::get()->byID($id);
        $groups = $this->getParent()->Groups();

        if ($groups->count() > 0 && !$member->inGroups($groups)) {
            $this->httpError(403);
        }

        $sections     = $this->getParent()->Sections();
        $sectionsList = ArrayList::create();

        foreach ($sections as $section) {
            $sectionsList->push($section);
            $section->setMember($member);
        }

        $this->data()->Title = sprintf(
            _t('MemberProfiles.MEMBERPROFILETITLE', "%s's Profile"),
            $member->getName()
        );
        $this->data()->Parent = $this->getParent();

        return $this->customise(array(
            'Type'     => 'View',
            'Member'   => $member,
            'Sections' => $sectionsList,
            'IsSelf'   => $member->ID == Security::getCurrentUser()
        ));
    }

    /**
     * @var MemberProfilePageController
     */
    protected function getParent(): MemberProfilePageController
    {
        return $this->parent;
    }

    /**
     * @var string
     */
    protected function getName()
    {
        return $this->name;
    }

    /**
     * @return int
     */
    /*public function getPaginationStart()
    {
        if ($start = $this->request->getVar('start')) {
            if (ctype_digit($start) && (int) $start > 0) {
                return (int) $start;
            }
        }

        return 0;
    }*/

    /**
     * @return string
     */
    public function Link($action = null)
    {
        return Controller::join_links($this->getParent()->Link(), $this->getName(), $action);
    }
}
