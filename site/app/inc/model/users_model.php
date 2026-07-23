<?php
class users_model extends DOLModel
{
    protected array $field = [" idx ", " name ", " mail ", " login "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("users");
    }
}
