<?php

namespace Foundation\Argparse;

class _ArgumentGroup extends _ActionsContainer
{
    public ?string $title;
    /** @var list<Action> */
    public array $_group_actions = [];

    public function __construct(_ActionsContainer $container, ?string $title = null, ?string $description = null)
    {
        parent::__construct($description);

        // group attributes
        $this->title = $title;

        // share most attributes with the container
        $this->_actions = &$container->_actions;
        $this->_option_string_actions = &$container->_option_string_actions;
    }

    public function _add_action(Action $action): Action
    {
        $action = parent::_add_action($action);
        $this->_group_actions[] = $action;
        return $action;
    }
}
