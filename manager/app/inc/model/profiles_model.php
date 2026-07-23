<?php
class profiles_model extends DOLModel
{
    protected array $field = ["idx", "name", "editabled", "slug", "adm", "parent"];
    protected array $filter = ["active = 'yes'"];

    function __construct()
    {
        parent::__construct("profiles");
    }
}
