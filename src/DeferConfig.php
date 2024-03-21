<?php

namespace Programster\PgsqlObjects;

enum DeferConfig: string
{
    case INITIALLY_DEFERRED = "DEFERRABLE INITIALLY DEFERRED";
    case INITIALLY_IMMEDIATE = "DEFERRABLE INITIALLY IMMEDIATE";
    case NOT_DEFERRABLE = "NOT DEFERRABLE";
}