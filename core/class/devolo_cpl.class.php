<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class devolo_cpl extends eqLogic {
    /*     * *************************Attributs****************************** */

    /*     * ***********************Methode static*************************** */

    /*
    * Fonction exécutée automatiquement toutes les minutes par Jeedom
    public static function cron() {}
    */

    /*
    * Fonction exécutée automatiquement toutes les 5 minutes par Jeedom
    */
    public static function cron5() {
	$equipements = eqLogic::byType(__CLASS__,True);
	foreach($equipements as $equipement) {
	    $equipement->getEqState();
	}
    }

    /*
    * Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
    public static function cron10() {}
    */

    /*
    * Fonction exécutée automatiquement toutes les 15 minutes par Jeedom
    public static function cron15() {}
    */

    /*
    * Fonction exécutée automatiquement toutes les 30 minutes par Jeedom
    public static function cron30() {}
    */

    /*
    * Fonction exécutée automatiquement toutes les heures par Jeedom
    public static function cronHourly() {}
    */

    /*
    * Fonction exécutée automatiquement tous les jours par Jeedom
    public static function cronDaily() {}
    */

   // public static function dependancy_install() {
   //     log::remove(__CLASS__ . '_update');
   //     return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependency', 'log' => log::getPathToLog(__CLASS__ . '_update'));
   // }

   // public static function dependancy_info() {
   //     $return = array();
   //     $return['log'] = log::getPathToLog(__CLASS__ . '_update');
   //     $return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependency';
   //     if (file_exists(jeedom::getTmpFolder(__CLASS__) . '/dependency')) {
   //         $return['state'] = 'in_progress';
   //     } else {
   //         if (exec(system::getCmdSudo() . 'pip3 list | grep -Ewc "devolo-plc-api"') < 1) {
   //             $return['state'] = 'nok';
   //         } else {
   //             $return['state'] = 'ok';
   //         }
   //     }
   //     return $return;
   // }

    public static function deamon_info() {
        $return = array();
        $return['log'] = __CLASS__;
        $return['state'] = 'nok';
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            if (@posix_getsid(trim(file_get_contents($pid_file)))) {
                $return['state'] = 'ok';
            } else {
                shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
            }
        }
        $return['launchable'] = 'ok';
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
        }

        $path = realpath(dirname(__FILE__) . '/../../resources/bin/');
        $cmd = 'python3 ' . $path . '/devolo_cpld.py';
        $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
        $cmd .= ' --socketport ' . config::byKey('daemon::port', __CLASS__ );
        $cmd .= ' --callback ' . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/devolo_cpl/core/php/jeedevolo_cpl.php'; // chemin de la callback url à modifier (voir ci-dessous)
        $cmd .= ' --apikey ' . jeedom::getApiKey(__CLASS__); // l'apikey pour authentifier les échanges suivants
        $cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        log::add(__CLASS__, 'info', 'Lancement démon');
        $result = exec($cmd . ' >> ' . log::getPathToLog('devolo_cpl_daemon') . ' 2>&1 &');
        $i = 0;
        while ($i < 20) {
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] == 'ok') {
                break;
            }
            sleep(1);
            $i++;
        }
        if ($i >= 30) {
            log::add(__CLASS__, 'error', __('Impossible de lancer le démon, vérifiez le log', __FILE__), 'unableStartDeamon');
            return false;
        }
        message::removeAll(__CLASS__, 'unableStartDeamon');
        return true;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid'; // ne pas modifier
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            system::kill($pid);
        }
        system::kill('devolo_cpld.py'); // nom du démon à modifier
        sleep(1);
    }

    public static function getModelInfos($model = Null) {
	$infos =  json_decode(file_get_contents(__DIR__ . "/../config/models.json"),true);
	$country = config::byKey('country','devolo_cpl','ch');
	$imgDir = __DIR__ . '/../../desktop/img/';
	foreach (array_keys($infos) as $m ) {
	    if (!array_key_exists('image',$infos[$m])){
		continue;
	    }
	    $img = $country . '-' . $infos[$m]['image'];
	    if (file_exists($imgDir . $img)){
		$infos[$m]['image'] = $img;
	    }
	}
	if ($model == Null) {
	    return $infos;
	}
	if (array_key_exists($model,$infos)){
	    return $infos[$model];
	}
	return Null;
    }

    public static function createOrUpdate($equipement){
	if (!is_array($equipement)) {
	    throw new Exception(__('Information reçues incorrectes',__FILE__));
	}

	if (!array_key_exists('serial',$equipement)) {
	    throw new Exception (__("Le n° de serie est indéfini!",__FILE__));
	}
	if (!array_key_exists('name',$equipement) || $equipement['name'] == '') {
	    $equipement['name'] = $equipement['serial'];
	}
	$eqLogic = devolo_cpl::byLogicalId($equipement['serial'],__CLASS__);
	if (is_object($eqLogic)) {
	    log::add("devolo_cpl","debug",sprintf(__("Mise à jour de '%s'",__FILE__),$equipement['name']));
	    $modified = false;
	    if ($eqLogic->getName() != $equipement['name']){
		log::add("devolo_cpl","info",__("Le nom de l'équipement a été changé:",__FILE__) . " " . $eqLogic->getName() . " => " . $equipement['name']);
		$modified = true;
		$eqLogic->setName($equipement['name']);
	    }
	    if ($eqLogic->getConfiguration("sync_model") != $equipement['model']){
		log::add("devolo_cpl","info",sprintf(__("Le model de l'équipement %s été changé:",__FILE__),$eqLogic->getName()) . " " . $eqLogic->getConfiguration('model') . " => " . $equipement['model']);
		$modified = true;
		$eqLogic->setConfiguration('sync_model',$equipement['model']);
		$eqLogic->setConfiguration('model',$equipement['model']);
	    }
	    if ($eqLogic->getConfiguration("ip") != $equipement['ip']){
		log::add("devolo_cpl","info",sprintf(__("L'ip de l'équipement %s été changé:",__FILE__),$eqLogic->getName()) . " " . $eqLogic->getConfiguration('ip') . " => " . $equipement['ip']);
		$modified = true;
		$eqLogic->setConfiguration('ip',$equipement['ip']);
	    }
	    if ($modified) {
		$eqLogic->save();
	    }
	} else {
	    log::add("devolo_cpl","debug",sprintf(__("Créaction de '%s'",__FILE__),$equipement['name']));
	    $devolo = new devolo_cpl();
	    $devolo->setName($equipement['name']);
	    $devolo->setEqType_name(__CLASS__);
	    $devolo->setLogicalId($equipement['serial']);
	    $devolo->setConfiguration("sync_model",$equipement['model']);
	    $devolo->setConfiguration("ip",$equipement['ip']);
	    if (self::getModelInfos($equipement['model']) == Null) {
		$devolo->setConfiguration("model","autre");
	    } else {
		$devolo->setConfiguration("model",$equipement['model']);
	    }
	    $devolo->save();
	}
    }

    public static function syncDevolo() {
	$path = realpath(dirname(__FILE__) . '/../../resources/bin');
	$cmd = "python3 " . $path . '/devolo_cpl.py';
	$cmd .= ' --syncDevolo';
	$cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
	$cmd .= ' 2>>' . log::getPathToLog('devolo_cpl_out');
	$lines = [];
	$result = exec($cmd ,$lines, $exitStatus);
	if ($result === false) {
	    throw new Exception(__("Erreur lors du lancement de syncDevolo.py",__FILE__));
	}
	if ($exitStatus != 0) {
	    throw new Exception(__("Erreur lors de l'exécution de syncDevolo.py",__FILE__));
	}
	log::add("devolo_cpl","info", join(" ",$lines));
	$equipements = json_decode(join(" ",$lines),true);
	foreach ($equipements as $equipement) {
	    log::add("devolo_cpl","debug",print_r($equipement,true));
	    self::createOrUpdate($equipement);
	}
    }

    /*     * *********************Méthodes d'instance************************* */


    public function sendToDaemon($params) {
        $deamon_info = self::deamon_info();
        if ($deamon_info['state'] != 'ok') {
            throw new Exception("Le démon n'est pas démarré");
        }
        $params['apikey'] = jeedom::getApiKey(__CLASS__);
	$params['serial'] = $this->getLogicalId();
	$params['ip'] = $this->getConfiguration('ip');
	$params['password'] = $this->getConfiguration('password');
        $payLoad = json_encode($params);
        $socket = socket_create(AF_INET, SOCK_STREAM, 0);
        socket_connect($socket, '127.0.0.1', config::byKey('socketport', __CLASS__, config::byKey('daemon::port',__CLASS__)));
        socket_write($socket, $payLoad, strlen($payLoad));
        socket_close($socket);
    }
    // Remontée de l'état de l'équipement
    public function getEqState () {
	$this::sendToDaemon(['action' => 'getState']);
    }

    // Function pour la création des CMD
    private function createCmds () {
	log::add("devolo_cpl","info",sprintf(__("Création des commandes pour l'équipement %s (%s)",__FILE__),$this->getName(), $this->getLogicalId()));
	$cmdFile = realpath(__DIR__ . "/../config/cmds.json"); 
	$configs =  json_decode(file_get_contents($cmdFile),true);
	foreach ($configs as $logicalId => $config) {
	    log::add("devolo_cpl","info",sprintf(__("  cmd: %s (première passe)",__FILE__),$logicalId));
	    $cmd = $this->getCmd(null, $logicalId);
	    if (is_object($cmd)) {
		log::add("devolo_cpl","warning",sprintf(__("La commande '%s' exist déjà",__FILE__),$logicalId));
		continue;
	    }
	    $cmd = new devolo_cplCMD();
	    $cmd->setEqLogic_id($this->getId());
	    $cmd->setLogicalId($logicalId);
	    $cmd->setName(translate::exec($config['name'],$cmdFile));
	    $cmd->setType($config['type']);
	    $cmd->setSubType($config['subType']);
	    if (isset($config['visible'])){
		    $cmd->setIsVisible($config['visible']);
	    }
	    if (isset($config['template'])){
		    if (isset($config['template']['dashboard'])){
			    $cmd->setTemplate('dashboard',$config['template']['dashboard']);
		    }
		    if (isset($config['template']['mobile'])){
			    $cmd->setTemplate('mobile',$config['template']['mobile']);
		    }
	    }
	    $cmd->save();
	}
	foreach ($configs as $logicalId => $config) {
	    log::add("devolo_cpl","info",sprintf(__("  cmd: %s (seconde passe)",__FILE__),$logicalId));
	    if (isset($config['value'])){
		$cmdLiee = $this->getCmd(null,$config['value']);
		if (! is_object($cmdLiee)){
		    log::add("devolo_cpl","errror",sprintf(__("La commande '%s' est introuvable",__FILE__),$config['value']));
		    continue;
		}
		$cmd = $this->getCmd(null,$logicalId);
		if (! is_object($cmd)){
		    log::add("devolo_cpl","errror",sprintf(__("La commande '%s' est introuvable",__FILE__),$logicalId));
		    continue;
		}
		$cmd->setValue($cmdLiee->getId());
		$cmd->save();
	    }
	}
    }

    // Fonction exécutée automatiquement avant la création de l'équipement
    public function preInsert() {
    }

    // Fonction exécutée automatiquement après la création de l'équipement
    public function postInsert() {
	$this->createCmds();
    }

    // Fonction exécutée automatiquement avant la mise à jour de l'équipement
    public function preUpdate() {
    }

    // Fonction exécutée automatiquement après la mise à jour de l'équipement
    public function postUpdate() {
    }

    // Fonction exécutée automatiquement avant la sauvegarde (création ou mise à jour) de l'équipement
    public function preSave() {
    }

    // Fonction exécutée automatiquement après la sauvegarde (création ou mise à jour) de l'équipement
    public function postSave() {
    }

    // Fonction exécutée automatiquement avant la suppression de l'équipement
    public function preRemove() {
    }

    // Fonction exécutée automatiquement après la suppression de l'équipement
    public function postRemove() {
    }

    /*
    * Permet de crypter/décrypter automatiquement des champs de configuration des équipements
    * Exemple avec le champ "Mot de passe" (password)
    */
    public function decrypt() {
      $this->setConfiguration('password', utils::decrypt($this->getConfiguration('password')));
    }
    public function encrypt() {
      $this->setConfiguration('password', utils::encrypt($this->getConfiguration('password')));
    }

    public function getImage() {
	$model = $this->getConfiguration('model');
	if ($model != "") {
	    $infos = $this->getModelInfos($model);
	    if (is_array($infos) and array_key_exists('image',$infos)) {
		return '/plugins/devolo_cpl/desktop/img/' . $infos['image'];
	    }
	}
	return parent::getImage();
    }

    /*     * **********************Getteur Setteur*************************** */

}

class devolo_cplCmd extends cmd {
    /*     * *************************Attributs****************************** */

    /*
    public static $_widgetPossibility = array();
    */

    /*     * ***********************Methode static*************************** */


    /*     * *********************Methode d'instance************************* */

    /*
    * Permet d'empêcher la suppression des commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
    public function dontRemoveCmd() {
      return true;
    }
    */

    private function sendActionToDaemon ($action, $param = Null, $refresh=true) {
	$params = [
	    'action' => "execCmd",
	    'cmd' => $action,
	    'param' => $param,
	    'refresh' => $refresh
	];
	$this->getEqLogic()->sendToDaemon($params);
    }

    // Exécution d'une commande
    public function execute($_options = array()) {
	if ($this->getLogicalId() == 'refresh') {
	    $this->getEqLogic()->getEqState();
	}
	if ($this->getLogicalId() == 'leds_on') {
	    $this->sendActionToDaemon('leds', 1);
	}
	if ($this->getLogicalId() == 'leds_off') {
	    $this->sendActionToDaemon('leds', 0);
	}
    }

    /*     * **********************Getteur Setteur*************************** */

}
