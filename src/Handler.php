<?php

/**
 * @file
 * Contains \DrupalComposer\DrupalScaffold\Handler.
 */

namespace DrupalComposer\DrupalScaffold;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

class Handler {

  /**
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * @var \Composer\Package\PackageInterface
   */
  protected $drupalCorePackage;

  /**
   * Handler constructor.
   *
   * @param Composer $composer
   * @param IOInterface $io
   */
  public function __construct(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * Marks scaffolding to be processed after an install or update command.
   *
   * @param \Composer\Installer\PackageEvent $event
   */
  public function onPostPackageEvent(\Composer\Installer\PackageEvent $event){
    $operation = $event->getOperation();
    if ($operation instanceof InstallOperation) {
      $package = $operation->getPackage();
    }
    elseif ($operation instanceof UpdateOperation) {
      $package = $operation->getTargetPackage();
    }

    if (isset($package) && $package instanceof PackageInterface && $package->getName() == 'drupal/core') {
      // By explicitiley setting the core package, the onPostCmdEvent() will
      // process the scaffolding automatically.
      $this->drupalCorePackage = $package;
    }
  }

  /**
   * Post command event to execute the scaffolding.
   *
   * @param \Composer\Script\Event $event
   */
  public function onPostCmdEvent(\Composer\Script\Event $event) {
    // Only trigger scaffold download, when the drupal core package
    if (isset($this->drupalCorePackage)) {
      $this->downloadScaffold();
    }
  }

  /**
   * Downloads drupal scaffold files for the current process.
   */
  public function downloadScaffold() {
    $drupalCorePackage = $this->getDrupalCorePackage();
    $installationManager = $this->composer->getInstallationManager();
    $corePath = $installationManager->getInstallPath($drupalCorePackage);
    // Webroot is the parent path of the drupal core installation path.
    $webroot = dirname($corePath);

    // Collect excludes and settings files.
    $excludes = $this->getExcludes();
    $settingsFiles = $this->getSettingsFiles();

    // Fetch options, and pass values to Robo based on the method
    // selected to download the scaffold files.
    $options = $this->getOptions();
    $extra = [];
    switch ($options['method']) {
      case 'drush':
        $roboCommand = 'drupal_scaffold:drush_download';
        $extra = ['--drush', $this->getDrushDir() . '/drush'];
        break;
      case 'http':
        $roboCommand = 'drupal_scaffold:http_download';
        $extra = ['--source', $options['source']];
        break;
    }

    // Run Robo
    $robo = new RoboRunner();
    $robo->execute(array_merge(
      [
        'robo',
        $roboCommand,
        $drupalCorePackage->getPrettyVersion(),
        '--webroot',
        $webroot,
        '--excludes',
        implode(RoboFile::DELIMITER_EXCLUDE, $excludes),
        '--settings',
        implode(RoboFile::DELIMITER_EXCLUDE, $settingsFiles),
      ],
      $extra
    ));
  }

  /**
   * Look up the Drupal core package object, or return it from where we cached
   * it in the $drupalCorePackage field.
   *
   * @return PackageInterface
   */
  public function getDrupalCorePackage() {
    if (!isset($this->drupalCorePackage)) {
      $this->drupalCorePackage = $this->getPackage('drupal/core');
    }
    return $this->drupalCorePackage;
  }

  /**
   * Helper to get the drush directory.
   *
   * @return string
   *   The absolute path for the drush directory.
   */
  public function getDrushDir() {
    $package = $this->getPackage('drush/drush');
    if ($package) {
      return $this->composer->getInstallationManager()->getInstallPath($package);
    }
  }

  /**
   * Retrieve a package from the current composer process.
   *
   * @param string $name
   *   Name of the package to get from the current composer installation.
   *
   * @return PackageInterface
   */
  protected function getPackage($name) {
    return $this->composer->getRepositoryManager()->getLocalRepository()->findPackage($name, '*');
  }

  /**
   * Retrieve excludes from optional "extra" configuration.
   *
   * @return array
   */
  protected function getExcludes() {
    return getNamedOptionList('excludes', 'getExcludesDefault');
  }

  /**
   * Retrieve list of additional settings files from optional "extra" configuration.
   *
   * @return array
   */
  protected function getSettingsFiles() {
    return getNamedOptionList('settings', 'getSettingFilesDefault');
  }

  /**
   * Retrieve a named list of options from optional "extra" configuration.
   * Respects 'omit-defaults', and either includes or does not include the
   * default values, as requested.
   *
   * @return array
   */
  protected function getNamedOptionList($optionName, $defaultFn) {
    $options = $this->getOptions($this->composer);
    $result = array();
    if (empty($options['omit-defaults'])) {
      $result = $this->getSettingFilesDefault();
    }
    $result = array_merge($result, (array) $options[$optionName]);

    return $result;
  }

  /**
   * Retrieve excludes from optional "extra" configuration.
   *
   * @return array
   */
  protected function getOptions() {
    $extra = $this->composer->getPackage()->getExtra() + ['drupal-scaffold' => []];
    $options = $extra['drupal-scaffold'] + [
      'omit-defaults' => FALSE,
      'excludes' => [],
      'settings' => [],
      'method' => 'drush',
      'source' => 'http://ftp.drupal.org/files/projects/drupal-{version}.tar.gz',
    ];
    return $options;
  }

  /**
   * Holds default excludes.
   */
  protected function getExcludesDefault() {
    return [
      '.gitkeep',
      'autoload.php',
      'composer.json',
      'composer.lock',
      'core',
      'drush',
      'example.gitignore',
      'LICENSE.txt',
      'README.txt',
      'vendor',
      'sites',
      'themes',
      'profiles',
      'modules',
    ];
  }

  /**
   * Holds default settings files list.
   */
  protected function getSettingFilesDefault() {
    return [
      'sites/default/default.settings.php',
      'sites/default/default.services.yml',
      'sites/example.settings.local.php',
      'sites/example.sites.php'
    ];
  }
}
