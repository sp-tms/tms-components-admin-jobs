<?php

namespace Apps\Tms\Components\Jobs\Install;

use System\Base\BasePackage;
use System\Base\Providers\ModulesServiceProvider\FilterInstaller;
use System\Base\Providers\ModulesServiceProvider\MenuInstaller;

class Install extends BasePackage
{
    protected $menuInstaller;

    protected $filterInstaller;

    public function init()
    {
        $this->menuInstaller = new MenuInstaller;

        $this->filterInstaller = new FilterInstaller;

        return $this;
    }

    public function install()
    {
        $this->installMenu();

        $this->installFilters();

        return true;
    }

    protected function installMenu()
    {
        $this->menuInstaller->installMenu($this);

        return true;
    }

    protected function installFilters()
    {
        $this->filterInstaller->installFilters($this);

        return true;
    }

    public function uninstall($remove = false)
    {
        if ($remove) {
            $this->menuInstaller->uninstallMenu($this);

            $this->filterInstaller->uninstallFilters($this);
        }

        return true;
    }
}