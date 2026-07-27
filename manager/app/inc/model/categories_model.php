<?php
class categories_model extends DOLModel
{
    protected array $field = [" idx ", " name "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("categories");
    }
}
