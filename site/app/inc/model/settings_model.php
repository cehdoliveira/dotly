<?php
class settings_model extends DOLModel
{
    protected array $field = [" idx ", " skey ", " svalue "];
    protected array $filter = [" active = 'yes' "];
    function __construct() { parent::__construct("settings"); }
}
