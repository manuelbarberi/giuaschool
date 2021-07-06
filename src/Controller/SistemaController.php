<?php
/**
 * giua@school
 *
 * Copyright (c) 2017-2021 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017-2021
 */


namespace App\Controller;


use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Doctrine\Bundle\DoctrineBundle\ConnectionFactory;
use App\Form\ConfigurazioneType;
use App\Form\UtenteType;
use App\Form\ModuloType;
use App\Util\LogHandler;
use App\Util\ArchiviazioneUtil;
use App\Entity\Istituto;
use App\Entity\Sede;
use App\Entity\Corso;
use App\Entity\Materia;
use App\Entity\Classe;
use App\Entity\Preside;
use App\Entity\Staff;
use App\Entity\Docente;
use App\Entity\Ata;
use App\Entity\Alunno;
use App\Entity\Genitore;
use App\Entity\StoricoEsito;
use App\Entity\StoricoVoto;
use App\Entity\Documento;
use App\Entity\Provisioning;


/**
 * SistemaController - gestione parametri di sistema e funzioni di utlità
 */
class SistemaController extends BaseController {

  /**
   * Configura la visualizzazione di un banner sulle pagine principali.
   *
   * @param Request $request Pagina richiesta
   * @param EntityManagerInterface $em Gestore delle entità
   *
   * @return Response Pagina di risposta
   *
   * @Route("/sistema/banner/", name="sistema_banner",
   *    methods={"GET", "POST"})
   *
   * @IsGranted("ROLE_AMMINISTRATORE")
   */
  public function bannerAction(Request $request, EntityManagerInterface $em): Response {
    // init
    $dati = [];
    $info = [];
    // legge parametri
    $banner_login = $em->getRepository('App:Configurazione')->getParametro('banner_login', '');
    $banner_home = $em->getRepository('App:Configurazione')->getParametro('banner_home', '');
    // form
    $form = $this->createForm(ConfigurazioneType::class, null, ['formMode' => 'banner',
      'dati' => [$banner_login, $banner_home]]);
    $form->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
      // memorizza i parametri
      $em->getRepository('App:Configurazione')->setParametro('banner_login',
        $form->get('banner_login')->getData() ? $form->get('banner_login')->getData() : '');
      $em->getRepository('App:Configurazione')->setParametro('banner_home',
        $form->get('banner_home')->getData() ? $form->get('banner_home')->getData() : '');
    }
    // mostra la pagina di risposta
    return $this->renderHtml('sistema', 'banner', $dati, $info, [$form->createView(), 'message.banner']);
  }

  /**
   * Gestione della modalità manutenzione del registro
   *
   * @param Request $request Pagina richiesta
   * @param EntityManagerInterface $em Gestore delle entità
   *
   * @return Response Pagina di risposta
   *
   * @Route("/sistema/manutenzione/", name="sistema_manutenzione",
   *    methods={"GET", "POST"})
   *
   * @IsGranted("ROLE_AMMINISTRATORE")
   */
  public function manutenzioneAction(Request $request, EntityManagerInterface $em): Response {
    // init
    $dati = [];
    $info = [];
    // legge parametri
    $manutenzione_inizio = $em->getRepository('App:Configurazione')->getParametro('manutenzione_inizio', null);
    $manutenzione_fine = $em->getRepository('App:Configurazione')->getParametro('manutenzione_fine', null);
    if (!$manutenzione_inizio) {
      // non è impostata una manutenzione
      $manutenzione = false;
      $manutenzione_inizio = new \DateTime();
      $manutenzione_inizio->modify('+'.(10 - $manutenzione_inizio->format('i') % 10).' minutes');
      $manutenzione_fine = (clone $manutenzione_inizio)->modify('+30 minutes');
    } else {
      // è già impostata una manutenzione
      $manutenzione = true;
      $manutenzione_inizio = \DateTime::createFromFormat('Y-m-d H:i', $manutenzione_inizio);
      $manutenzione_fine = \DateTime::createFromFormat('Y-m-d H:i', $manutenzione_fine);
    }
    // form
    $form = $this->createForm(ConfigurazioneType::class, null, ['formMode' => 'manutenzione',
      'dati' => [$manutenzione, $manutenzione_inizio, clone $manutenzione_inizio,
        $manutenzione_fine, clone $manutenzione_fine]]);
    $form->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
      // form inviato
      if ($form->get('manutenzione')->getData()) {
        // imposta manutenzione
        $param_inizio = $form->get('data_inizio')->getData()->format('Y-m-d').' '.
          $form->get('ora_inizio')->getData()->format('H:i');
        $param_fine = $form->get('data_fine')->getData()->format('Y-m-d').' '.
          $form->get('ora_fine')->getData()->format('H:i');
        if ($param_inizio > $param_fine) {
          // inverte l'ordine
          $temp = $param_inizio;
          $param_inizio = $param_fine;
          $param_fine = $temp;
        }
      } else {
        // cancella manutenzione
        $param_inizio = '';
        $param_fine = '';
      }
      // memorizza i parametri
      $em->getRepository('App:Configurazione')->setParametro('manutenzione_inizio', $param_inizio);
      $em->getRepository('App:Configurazione')->setParametro('manutenzione_fine', $param_fine);
    }
    // mostra la pagina di risposta
    return $this->renderHtml('sistema', 'manutenzione', $dati, $info, [$form->createView(), 'message.manutenzione']);
  }

  /**
   * Configurazione dei parametri dell'applicazione
   *
   * @param Request $request Pagina richiesta
   * @param EntityManagerInterface $em Gestore delle entità
   *
   * @return Response Pagina di risposta
   *
   * @Route("/sistema/parametri/", name="sistema_parametri",
   *    methods={"GET", "POST"})
   *
   * @IsGranted("ROLE_AMMINISTRATORE")
   */
  public function parametriAction(Request $request, EntityManagerInterface $em): Response {
    // init
    $dati = [];
    $info = [];
    // legge parametri
    $parametri = $em->getRepository('App:Configurazione')->parametriConfigurazione();
    // form
    $form = $this->createForm(ConfigurazioneType::class, null, ['formMode' => 'parametri',
      'dati' => $parametri]);
    $form->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
      // memorizza modifiche
      $em->flush();
    }
    // mostra la pagina di risposta
    return $this->renderHtml('sistema', 'parametri', $dati, $info, [$form->createView(), 'message.parametri']);
  }

  /**
   * Cambia la password di un utente
   *
   * @param Request $request Pagina richiesta
   * @param EntityManagerInterface $em Gestore delle entità
   * @param UserPasswordEncoderInterface $encoder Gestore della codifica delle password
   * @param TranslatorInterface $trans Gestore delle traduzioni
   * @param ValidatorInterface $validator Gestore della validazione dei dati
   * @param LogHandler $dblogger Gestore dei log su database
   *
   * @return Response Pagina di risposta
   *
   * @Route("/sistema/password/", name="sistema_password",
   *    methods={"GET", "POST"})
   *
   * @IsGranted("ROLE_AMMINISTRATORE")
   */
  public function passwordAction(Request $request, EntityManagerInterface $em,
                                 UserPasswordEncoderInterface $encoder, TranslatorInterface $trans,
                                 ValidatorInterface $validator, LogHandler $dblogger): Response {
    // init
    $dati = [];
    $info = [];
    // form
    $form = $this->createForm(UtenteType::class, null, ['formMode' => 'password']);
    $form->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
      // form inviato
      $username = $form->get('username')->getData();
      $user = $em->getRepository('App:Utente')->findOneByUsername($username);
      if (!$user || !$user->getAbilitato()) {
        // errore, utente non esiste o non abilitato
        $form->get('username')->addError(new FormError($trans->trans('exception.invalid_user')));
      } else {
        // validazione password
        $user->setPasswordNonCifrata($form->get('password')->getData());
        $errors = $validator->validate($user);
        if (count($errors) > 0) {
          // errore sulla password
          $form->get('password')->get('first')->addError(new FormError($errors[0]->getMessage()));
        } else {
          // codifica password
          $password = $encoder->encodePassword($user, $user->getPasswordNonCifrata());
          $user->setPassword($password);
          // provisioning
          if (($user instanceOf Docente) || ($user instanceOf Alunno)) {
            $provisioning = (new Provisioning())
              ->setUtente($user)
              ->setFunzione('passwordUtente')
              ->setDati(['password' => $user->getPasswordNonCifrata()]);
            $em->persist($provisioning);
          }
          // memorizza password
          $em->flush();
          // log azione
          $dblogger->write($user, $request->getClientIp(), 'SICUREZZA', 'Cambio Password da Amministrazione', __METHOD__, array(
            'Username esecutore' => $this->getUser()->getUsername(),
            'Ruolo esecutore' => $this->getUser()->getRoles()[0],
            'ID esecutore' => $this->getUser()->getId()
            ));
        }
      }
    }
    // mostra la pagina di risposta
    return $this->renderHtml('sistema', 'password', $dati, $info, [$form->createView(), 'message.password']);
  }

  /**
   * Impersona un altro utente
   *
   * @param Request $request Pagina richiesta
   * @param EntityManagerInterface $em Gestore delle entità
   * @param SessionInterface $session Gestore delle sessioni
   * @param TranslatorInterface $trans Gestore delle traduzioni
   * @param LogHandler $dblogger Gestore dei log su database
   *
   * @return Response Pagina di risposta
   *
   * @Route("/sistema/alias/", name="sistema_alias",
   *    methods={"GET", "POST"})
   *
   * @IsGranted("ROLE_AMMINISTRATORE")
   */
  public function aliasAction(Request $request, EntityManagerInterface $em, SessionInterface $session,
                              TranslatorInterface $trans, LogHandler $dblogger): Response {
    // init
    $dati = [];
    $info = [];
    // form
    $form = $this->createForm(UtenteType::class, null, ['formMode' => 'alias']);
    $form->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
      // form inviato
      $username = $form->get('username')->getData();
      $user = $em->getRepository('App:Utente')->findOneByUsername($username);
      if (!$user || !$user->getAbilitato()) {
        // errore, utente non esiste o non abilitato
        $form->get('username')->addError(new FormError($trans->trans('exception.invalid_user')));
      } else {
        // memorizza dati in sessione
        $session->set('/APP/UTENTE/tipo_accesso_reale', $session->get('/APP/UTENTE/tipo_accesso'));
        $session->set('/APP/UTENTE/ultimo_accesso_reale', $session->get('/APP/UTENTE/ultimo_accesso'));
        $session->set('/APP/UTENTE/username_reale', $this->getUser()->getUsername());
        $session->set('/APP/UTENTE/ruolo_reale', $this->getUser()->getRoles()[0]);
        $session->set('/APP/UTENTE/id_reale', $this->getUser()->getId());
        $session->set('/APP/UTENTE/ultimo_accesso',
          ($user->getUltimoAccesso() ? $user->getUltimoAccesso()->format('d/m/Y H:i:s') : null));
        $session->set('/APP/UTENTE/tipo_accesso', 'alias');
        // cancella altri dati di sessione
        $session->remove('/APP/ROUTE');
        $session->remove('/APP/DOCENTE');
        // log azione
        $dblogger->write($user, $request->getClientIp(), 'ACCESSO', 'Alias', __METHOD__, array(
          'Username' => $user->getUsername(),
          'Ruolo' => $user->getRoles()[0],
          'Username reale' => $this->getUser()->getUsername(),
          'Ruolo reale' => $this->getUser()->getRoles()[0],
          'ID reale' => $this->getUser()->getId()
          ));
        // impersona l'alias e fa il redirect alla home
        return $this->redirectToRoute('login_home', array('reload' => 'yes', '_alias' => $username));
      }
    }
    // mostra la pagina di risposta
    return $this->renderHtml('sistema', 'alias', $dati, $info, [$form->createView(), 'message.alias']);
  }

  /**
   * Disconnette l'alias in uso e ritorna all'utente iniziale
   *
   * @param Request $request Pagina richiesta
   * @param SessionInterface $session Gestore delle sessioni
   * @param LogHandler $dblogger Gestore dei log su database
   *
   * @return Response Pagina di risposta
   *
   * @Route("/sistema/alias/exit", name="sistema_alias_exit",
   *    methods={"GET"})
   */
  public function aliasExitAction(Request $request, SessionInterface $session, LogHandler $dblogger): Response  {
    // log azione
    $dblogger->write($this->getUser(), $request->getClientIp(), 'ACCESSO', 'Alias Exit', __METHOD__, array(
      'Username' => $this->getUser()->getUsername(),
      'Ruolo' => $this->getUser()->getRoles()[0],
      'Username reale' => $session->get('/APP/UTENTE/username_reale'),
      'Ruolo reale' => $session->get('/APP/UTENTE/ruolo_reale'),
      'ID reale' => $session->get('/APP/UTENTE/id_reale')
      ));
    // ricarica dati in sessione
    $session->set('/APP/UTENTE/ultimo_accesso', $session->get('/APP/UTENTE/ultimo_accesso_reale'));
    $session->set('/APP/UTENTE/tipo_accesso', $session->get('/APP/UTENTE/tipo_accesso_reale'));
    $session->remove('/APP/UTENTE/tipo_accesso_reale');
    $session->remove('/APP/UTENTE/ultimo_accesso_reale');
    $session->remove('/APP/UTENTE/username_reale');
    $session->remove('/APP/UTENTE/ruolo_reale');
    $session->remove('/APP/UTENTE/id_reale');
    $session->remove('/APP/ROUTE');
    $session->remove('/APP/DOCENTE');
    // disconnette l'alias in uso e redirect alla home
    return $this->redirectToRoute('login_home', array('reload' => 'yes', '_alias' => '_exit'));
  }

  /**
   * Importa i dati dal precedente A.S.
   *
   * @param Request $request Pagina richiesta
   * @param EntityManagerInterface $em Gestore delle entità
   * @param ConnectionFactory $connessioneDB Gestore delle connessioni su database
   *
   * @return Response Pagina di risposta
   *
   * @Route("/sistema/importa/", name="sistema_importa",
   *    methods={"GET", "POST"})
   *
   * @IsGranted("ROLE_AMMINISTRATORE")
   */

  public function importaAction(Request $request, EntityManagerInterface $em,
                                ConnectionFactory $connessioneDB, TranslatorInterface $trans): Response {
    // init
    $dati = [];
    $info = [];
    // form
    $form = $this->createForm(ModuloType::class, null, ['formMode' => 'importa']);
    $form->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
      // connessione al database
      $params = array(
        'driver' => 'pdo_mysql',
        'version' => $this->getParameter('database_version'),
        'host' => $this->getParameter('database_host'),
        'port' => $this->getParameter('database_port'),
        'user' => $this->getParameter('database_user'),
        'password' => $this->getParameter('database_password'),
        'dbname' => $form->get('database')->getData());
      $conn = $connessioneDB->createConnection($params);
      try {
        $conn->connect();
      } catch (\Exception $e) {
        // errore sul database
        $form->get('database')->addError(new FormError($trans->trans('exception.database_error')));
      }
      // directory dell'Applicazione
      $dir = rtrim($form->get('directory')->getData(), '/').'/FILES/archivio/scrutini/';
      if (!is_dir($dir)) {
        // errore sul percorso principale
        $form->get('directory')->addError(new FormError($trans->trans('exception.directory_error')));
      }
      if ($form->isValid()) {
        // assicura che lo script non sia interrotto
        ini_set('max_execution_time', 0);
        // importa istituto/sedi
        if (in_array('I', $form->get('dati')->getData())) {
          // dati istituto
          $sql = "SELECT * FROM gs_istituto";
          $stmt = $conn->prepare($sql);
          $stmt->execute();
          $istituto_old = $stmt->fetch();
          $istituto = (new Istituto())
            ->setTipo($istituto_old['tipo'])
            ->setTipoSigla($istituto_old['tipo_sigla'])
            ->setNome($istituto_old['nome'])
            ->setNomeBreve($istituto_old['nome_breve'])
            ->setEmail($istituto_old['email'])
            ->setPec($istituto_old['pec'])
            ->setUrlRegistro($istituto_old['url_registro'])
            ->setUrlSito($istituto_old['url_sito'])
            ->setFirmaPreside($istituto_old['firma_preside'])
            ->setEmailAmministratore($istituto_old['email_amministratore'])
            ->setEmailNotifiche($istituto_old['email_notifiche']);
          $em->persist($istituto);
          // dati sedi
          $sql = "SELECT * FROM gs_sede ORDER BY ordinamento";
          $stmt = $conn->prepare($sql);
          $stmt->execute();
          foreach ($stmt->fetchAll() as $sede_old) {
            $sede = (new Sede())
              ->setNome($sede_old['nome'])
              ->setNomeBreve($sede_old['nome_breve'])
              ->setCitta($sede_old['citta'])
              ->setIndirizzo1(isset($sede_old['indirizzo1']) ? $sede_old['indirizzo1'] : $sede_old['indirizzo'])
              ->setIndirizzo2(isset($sede_old['indirizzo2']) ? $sede_old['indirizzo2'] : $sede_old['citta'])
              ->setTelefono($sede_old['telefono'])
              ->setOrdinamento($sede_old['ordinamento']);
            $em->persist($sede);
          }
          // memorizza dati
          $em->flush();
        }
        // importa corsi/materie
        if (in_array('C', $form->get('dati')->getData())) {
          // dati corsi
          $sql = "SELECT * FROM gs_corso ORDER BY nome";
          $stmt = $conn->prepare($sql);
          $stmt->execute();
          foreach ($stmt->fetchAll() as $corso_old) {
            $corso = (new Corso())
              ->setNome($corso_old['nome'])
              ->setNomeBreve($corso_old['nome_breve']);
            $em->persist($corso);
          }
          // dati materie
          $sql = "SELECT * FROM gs_materia ORDER BY nome";
          $stmt = $conn->prepare($sql);
          $stmt->execute();
          foreach ($stmt->fetchAll() as $materia_old) {
            $materia = (new Materia())
              ->setNome($materia_old['nome'])
              ->setNomeBreve($materia_old['nome_breve'])
              ->setTipo($materia_old['tipo'])
              ->setValutazione($materia_old['valutazione'])
              ->setMedia($materia_old['media'])
              ->setOrdinamento($materia_old['ordinamento']);
            $em->persist($materia);
          }
          // memorizza dati
          $em->flush();
        }
        // importa classi
        if (in_array('L', $form->get('dati')->getData())) {
          // dati classi
          $sql = "SELECT cl.*,c.nome AS c_nome,s.nome AS s_nome
            FROM gs_classe as cl,gs_sede as s,gs_corso as c
            WHERE cl.sede_id=s.id AND cl.corso_id=c.id
            ORDER BY sezione,anno";
          $stmt = $conn->prepare($sql);
          $stmt->execute();
          foreach ($stmt->fetchAll() as $classe_old) {
            $sede = $em->getRepository('App:Sede')->findOneByNome($classe_old['s_nome']);
            $corso = $em->getRepository('App:Corso')->findOneByNome($classe_old['c_nome']);
            $classe = (new Classe())
              ->setSede($sede)
              ->setCorso($corso)
              ->setAnno($classe_old['anno'])
              ->setSezione($classe_old['sezione'])
              ->setOreSettimanali($classe_old['ore_settimanali']);
            $em->persist($classe);
          }
          // memorizza dati
          $em->flush();
        }
        // importa alunni/genitori
        if (in_array('A', $form->get('dati')->getData())) {
          // dati alunni
          $sql = "SELECT a.*,g.username AS g_username,g.password AS g_password,g.email AS g_email,
              cl.anno,cl.sezione,e.esito
            FROM gs_utente AS a,gs_classe AS cl,gs_utente AS g,gs_esito e
            WHERE a.ruolo=:alunno AND a.abilitato=:abilitato AND a.classe_id=cl.id
            AND g.ruolo=:genitore AND g.alunno_id=a.id
            AND e.alunno_id=a.id AND e.esito NOT IN (:esiti)
            ORDER BY a.cognome,a.nome";
          $stmt = $conn->prepare($sql);
          $stmt->execute(['alunno' => 'ALU', 'abilitato' => 1, 'genitore' => 'GEN', 'esiti' => "'S','X'"]);
          foreach ($stmt->fetchAll() as $utente_old) {
            if (in_array($utente_old['esito'], ['A','E']) && $utente_old['anno'] == 5) {
              // sono esclusi alunni di quinta ammessi
              continue;
            }
            if (in_array($utente_old['esito'], ['A','E'])) {
              // ammesso (o all'estero): anno successivo
              $utente_old['anno']++;
            }
            // trova classe
            $classe = $em->getRepository('App:Classe')->findOneBy(['anno' => $utente_old['anno'],
              'sezione' => $utente_old['sezione']]);
            if (!$classe) {
              // cerca altra classe di stessa sede
              $sezione = $em->getRepository('App:Classe')->findOneBySezione($utente_old['sezione']);
              if ($sezione) {
                $classe = $em->getRepository('App:Classe')->findOneBy(['anno' => $utente_old['anno'],
                  'sede' => $sezione->getSede()]);
              }
            }
            // crea alunno
            $alunno = (new Alunno())
              ->setClasse($classe)
              ->setFoto($utente_old['foto'])
              ->setUsername($utente_old['username'])
              ->setPassword($utente_old['password'])
              ->setEmail($utente_old['email'])
              ->setAbilitato(true)
              ->setNome($utente_old['nome'])
              ->setCognome($utente_old['cognome'])
              ->setSesso($utente_old['sesso'])
              ->setDataNascita(\DateTime::createFromFormat('Y-m-d', $utente_old['data_nascita']))
              ->setComuneNascita($utente_old['comune_nascita'])
              ->setCodiceFiscale($utente_old['codice_fiscale'])
              ->setCitta($utente_old['citta'])
              ->setIndirizzo($utente_old['indirizzo'])
              ->setNumeriTelefono(unserialize($utente_old['numeri_telefono']))
              ->setNotifica(unserialize($utente_old['notifica']));
            $em->persist($alunno);
            // crea genitore
            $genitore = (new Genitore())
              ->setAlunno($alunno)
              ->setUsername($utente_old['g_username'])
              ->setPassword($utente_old['g_password'])
              ->setEmail($utente_old['g_email'])
              ->setAbilitato(true)
              ->setNome($utente_old['nome'])
              ->setCognome($utente_old['cognome'])
              ->setSesso($utente_old['sesso']);
            $em->persist($genitore);
          }
          // memorizza dati
          $em->flush();
        }
        // importa personale
        if (in_array('P', $form->get('dati')->getData())) {
          // dati preside
          $sql = "SELECT * FROM gs_utente WHERE ruolo=:ruolo AND abilitato=:abilitato ORDER BY cognome,nome";
          $stmt = $conn->prepare($sql);
          $stmt->execute(['ruolo' => 'PRE', 'abilitato' => 1]);
          foreach ($stmt->fetchAll() as $utente_old) {
            $preside = (new Preside())
              ->setChiave1($utente_old['chiave1'])
              ->setChiave2($utente_old['chiave2'])
              ->setChiave3($utente_old['chiave3'])
              ->setOtp($utente_old['otp'])
              ->setUsername($utente_old['username'])
              ->setPassword($utente_old['password'])
              ->setEmail($utente_old['email'])
              ->setAbilitato(true)
              ->setNome($utente_old['nome'])
              ->setCognome($utente_old['cognome'])
              ->setSesso($utente_old['sesso'])
              ->setDataNascita($utente_old['data_nascita'])
              ->setComuneNascita($utente_old['comune_nascita'])
              ->setCodiceFiscale($utente_old['codice_fiscale'])
              ->setCitta($utente_old['citta'])
              ->setIndirizzo($utente_old['indirizzo'])
              ->setNumeriTelefono(unserialize($utente_old['numeri_telefono']))
              ->setNotifica(unserialize($utente_old['notifica']));
            $em->persist($preside);
          }
          // dati staff
          $sql = "SELECT u.*,s.nome AS s_nome
            FROM gs_utente AS u LEFT JOIN gs_sede AS s ON u.sede_id=s.id
            WHERE u.ruolo=:ruolo AND u.abilitato=:abilitato
            ORDER BY cognome,nome";
          $stmt = $conn->prepare($sql);
          $stmt->execute(['ruolo' => 'STA', 'abilitato' => 1]);
          foreach ($stmt->fetchAll() as $utente_old) {
            $sede = $em->getRepository('App:Sede')->findOneByNome($utente_old['s_nome']);
            $staff = (new Staff())
              ->setSede($sede)
              ->setChiave1($utente_old['chiave1'])
              ->setChiave2($utente_old['chiave2'])
              ->setChiave3($utente_old['chiave3'])
              ->setOtp($utente_old['otp'])
              ->setUsername($utente_old['username'])
              ->setPassword($utente_old['password'])
              ->setEmail($utente_old['email'])
              ->setAbilitato(true)
              ->setNome($utente_old['nome'])
              ->setCognome($utente_old['cognome'])
              ->setSesso($utente_old['sesso'])
              ->setDataNascita($utente_old['data_nascita'])
              ->setComuneNascita($utente_old['comune_nascita'])
              ->setCodiceFiscale($utente_old['codice_fiscale'])
              ->setCitta($utente_old['citta'])
              ->setIndirizzo($utente_old['indirizzo'])
              ->setNumeriTelefono(unserialize($utente_old['numeri_telefono']))
              ->setNotifica(unserialize($utente_old['notifica']));
            $em->persist($staff);
          }
          // dati docenti
          $sql = "SELECT *
            FROM gs_utente AS u
            WHERE u.ruolo=:ruolo AND u.abilitato=:abilitato
            ORDER BY cognome,nome";
          $stmt = $conn->prepare($sql);
          $stmt->execute(['ruolo' => 'DOC', 'abilitato' => 1]);
          foreach ($stmt->fetchAll() as $utente_old) {
            $docente = (new Docente())
              ->setChiave1($utente_old['chiave1'])
              ->setChiave2($utente_old['chiave2'])
              ->setChiave3($utente_old['chiave3'])
              ->setOtp($utente_old['otp'])
              ->setUsername($utente_old['username'])
              ->setPassword($utente_old['password'])
              ->setEmail($utente_old['email'])
              ->setAbilitato(true)
              ->setNome($utente_old['nome'])
              ->setCognome($utente_old['cognome'])
              ->setSesso($utente_old['sesso'])
              ->setDataNascita($utente_old['data_nascita'])
              ->setComuneNascita($utente_old['comune_nascita'])
              ->setCodiceFiscale($utente_old['codice_fiscale'])
              ->setCitta($utente_old['citta'])
              ->setIndirizzo($utente_old['indirizzo'])
              ->setNumeriTelefono(unserialize($utente_old['numeri_telefono']))
              ->setNotifica(unserialize($utente_old['notifica']));
            $em->persist($docente);
          }
          // dati ATA
          $sql = "SELECT u.*,s.nome AS s_nome
            FROM gs_utente AS u LEFT JOIN gs_sede AS s ON u.sede_id=s.id
            WHERE u.ruolo=:ruolo AND u.abilitato=:abilitato
            ORDER BY cognome,nome";
          $stmt = $conn->prepare($sql);
          $stmt->execute(['ruolo' => 'ATA', 'abilitato' => 1]);
          foreach ($stmt->fetchAll() as $utente_old) {
            $sede = $em->getRepository('App:Sede')->findOneByNome($utente_old['s_nome']);
            $ata = (new Ata())
              ->setTipo($utente_old['tipo'])
              ->setSegreteria($utente_old['segreteria'])
              ->setSede($sede)
              ->setUsername($utente_old['username'])
              ->setPassword($utente_old['password'])
              ->setEmail($utente_old['email'])
              ->setAbilitato(true)
              ->setNome($utente_old['nome'])
              ->setCognome($utente_old['cognome'])
              ->setSesso($utente_old['sesso'])
              ->setDataNascita($utente_old['data_nascita'])
              ->setComuneNascita($utente_old['comune_nascita'])
              ->setCodiceFiscale($utente_old['codice_fiscale'])
              ->setCitta($utente_old['citta'])
              ->setIndirizzo($utente_old['indirizzo'])
              ->setNumeriTelefono(unserialize($utente_old['numeri_telefono']))
              ->setNotifica(unserialize($utente_old['notifica']));
            $em->persist($ata);
          }
          // memorizza dati
          $em->flush();
        }
        // importa esiti
        if (in_array('E', $form->get('dati')->getData())) {
          // alunni esistenti nel nuovo sistema
          $fs = new Filesystem();
          $alunni = $em->getRepository('App:Alunno')->createQueryBuilder('a')
            ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
            ->getQuery()
            ->getResult();
          // recupera dati scrutinio
          foreach ($alunni as $alu) {
            // legge esito e voti di alunno
            $sql = "SELECT a.id AS a_id,c.anno,c.sezione,e.esito,s.periodo,vs.unico,vs.debito,vs.dati,m.nome AS m_nome
              FROM gs_utente AS a,gs_classe AS c,gs_esito AS e,gs_scrutinio AS s,
                gs_voto_scrutinio AS vs,gs_materia AS m
              WHERE a.ruolo=:alunno AND a.codice_fiscale=:codfis AND a.classe_id=c.id
              AND e.alunno_id=a.id AND e.esito NOT IN (:esiti) AND e.scrutinio_id=s.id
              AND vs.scrutinio_id=s.id AND vs.alunno_id=a.id AND vs.materia_id=m.id";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['alunno' => 'ALU', 'codfis' => $alu->getCodiceFiscale(), 'esiti' => "'S','X'"]);
            $primo = true;
            $media = 0;
            $num_media = 0;
            foreach ($stmt->fetchAll() as $scrutinio) {
              if ($primo) {
                // imposta esito
                $esito = (new StoricoEsito())
                  ->setClasse($scrutinio['anno'].$scrutinio['sezione'])
                  ->setEsito($scrutinio['esito'])
                  ->setPeriodo($scrutinio['periodo'])
                  ->setAlunno($alu);
                $em->persist($esito);
                $primo = false;
              }
              // imposta voti
              $materia = $em->getRepository('App:Materia')->findOneByNome($scrutinio['m_nome']);
              $dati = unserialize($scrutinio['dati']);
              $scrutinio_dati = array();
              $carenze = null;
              if (($scrutinio['unico'] < 6 || ($materia->getTipo() == 'R' && $scrutinio['unico'] < 22))
                  && $scrutinio['anno'] < 5 && $scrutinio['esito'] == 'A') {
                $carenze = $scrutinio['debito'];
                $scrutinio_dati['strategie'] = isset($dati['strategie']) ? $dati['strategie'] : '';
              }
              $voto = (new StoricoVoto())
                ->setVoto($scrutinio['unico'])
                ->setCarenze($carenze)
                ->setDati($scrutinio_dati)
                ->setStoricoEsito($esito)
                ->setMateria($materia);
              $em->persist($voto);
              if ($materia->getMedia()) {
                $media += $scrutinio['unico'];
                $num_media++;
              }
            }
            // imposta media
            $esito->setMedia($media / $num_media);
            // copia documenti dello scrutinio
            $percorso = $this->getParameter('dir_scrutini').'/storico/'.$scrutinio['anno'].$scrutinio['sezione'];
            if (!$fs->exists($percorso)) {
              // crea directory
              $fs->mkdir($percorso, 0775);
            }
            // riepilogo voti
            $documento = $scrutinio['anno'].$scrutinio['sezione'].'-scrutinio-finale-riepilogo-voti.pdf';
            $percorso_old = $dir.'finale/'.$scrutinio['anno'].$scrutinio['sezione'];
            if ($fs->exists($percorso_old.'/'.$documento)) {
              $fs->copy($percorso_old.'/'.$documento, $percorso.'/'.$documento, false);
            }
            $documento = $scrutinio['anno'].$scrutinio['sezione'].'-scrutinio-integrativo-riepilogo-voti.pdf';
            $percorso_old = $dir.'integrativo/'.$scrutinio['anno'].$scrutinio['sezione'];
            if ($fs->exists($percorso_old.'/'.$documento)) {
              $fs->copy($percorso_old.'/'.$documento, $percorso.'/'.$documento, false);
            }
            $documento = $scrutinio['anno'].$scrutinio['sezione'].'-scrutinio-rinviato-riepilogo-voti.pdf';
            $percorso_old = $dir.'rinviato/'.$scrutinio['anno'].$scrutinio['sezione'];
            if ($fs->exists($percorso_old.'/'.$documento)) {
              $fs->copy($percorso_old.'/'.$documento, $percorso.'/'.$documento, false);
            }
            // verbale
            $documento = $scrutinio['anno'].$scrutinio['sezione'].'-scrutinio-finale-verbale.pdf';
            $percorso_old = $dir.'finale/'.$scrutinio['anno'].$scrutinio['sezione'];
            if ($fs->exists($percorso_old.'/'.$documento)) {
              $fs->copy($percorso_old.'/'.$documento, $percorso.'/'.$documento, false);
            }
            $documento = $scrutinio['anno'].$scrutinio['sezione'].'-scrutinio-integrativo-verbale.pdf';
            $percorso_old = $dir.'integrativo/'.$scrutinio['anno'].$scrutinio['sezione'];
            if ($fs->exists($percorso_old.'/'.$documento)) {
              $fs->copy($percorso_old.'/'.$documento, $percorso.'/'.$documento, false);
            }
            $documento = $scrutinio['anno'].$scrutinio['sezione'].'-scrutinio-rinviato-verbale.pdf';
            $percorso_old = $dir.'rinviato/'.$scrutinio['anno'].$scrutinio['sezione'];
            if ($fs->exists($percorso_old.'/'.$documento)) {
              $fs->copy($percorso_old.'/'.$documento, $percorso.'/'.$documento, false);
            }
            // PIA
            $documento = $scrutinio['anno'].$scrutinio['sezione'].'-piano-di-integrazione-degli-apprendimenti.pdf';
            $percorso_old = $dir.'finale/'.$scrutinio['anno'].$scrutinio['sezione'];
            if ($fs->exists($percorso_old.'/'.$documento)) {
              $fs->copy($percorso_old.'/'.$documento, $percorso.'/'.$documento, false);
              $esito_dati = $esito->getDati();
              $esito_dati['PIA'] = 'FILES/archivio/scrutini/storico/'.$scrutinio['anno'].$scrutinio['sezione'].'/'.$documento;
              $esito->setDati($esito_dati);
            }
            // PAI
            $documento = $scrutinio['anno'].$scrutinio['sezione'].'-piano-di-apprendimento-individualizzato-'.
              $scrutinio['a_id'].'.pdf';
            $percorso_old = $dir.'finale/'.$scrutinio['anno'].$scrutinio['sezione'];
            if ($fs->exists($percorso_old.'/'.$documento)) {
              $fs->copy($percorso_old.'/'.$documento, $percorso.'/'.$documento, false);
              $esito_dati = $esito->getDati();
              $esito_dati['PAI'] = 'FILES/archivio/scrutini/storico/'.$scrutinio['anno'].$scrutinio['sezione'].'/'.$documento;
              $esito->setDati($esito_dati);
            }
          }
          // memorizza dati
          $em->flush();
        }
      }
    }
    // mostra la pagina di risposta
    return $this->renderHtml('sistema', 'importa', $dati, $info, [$form->createView(), 'message.importa']);
  }

  /**
   * Gestione dell'archiviazione dei registri in PDF
   *
   * @param Request $request Pagina richiesta
   * @param EntityManagerInterface $em Gestore delle entità
   * @param TranslatorInterface $trans Gestore delle traduzioni
   * @param ArchiviazioneUtil $arch Funzioni di utilità per l'archiviazione
   *
   * @return Response Pagina di risposta
   *
   * @Route("/sistema/archivia/", name="sistema_archivia",
   *    methods={"GET", "POST"})
   *
   * @IsGranted("ROLE_AMMINISTRATORE")
   */
  public function archiviaAction(Request $request, EntityManagerInterface $em, TranslatorInterface $trans,
                                 ArchiviazioneUtil $arch): Response {
    // init
    $dati = [];
    $info = [];
    $lista_docenti = $em->getRepository('App:Docente')->createQueryBuilder('d')
      ->join('App:Cattedra', 'c', 'WITH', 'c.docente=d.id')
      ->join('c.materia', 'm')
      ->where('m.tipo IN (:tipi)')
      ->orderBy('d.cognome,d.nome', 'ASC')
      ->setParameters(['tipi' => ['N', 'R', 'E']])
      ->getQuery()
      ->getResult();
    $lista_sostegno = $em->getRepository('App:Docente')->createQueryBuilder('d')
      ->join('App:Cattedra', 'c', 'WITH', 'c.docente=d.id')
      ->join('c.materia', 'm')
      ->where('m.tipo=:tipo')
      ->orderBy('d.cognome,d.nome', 'ASC')
      ->setParameters(['tipo' => 'S'])
      ->getQuery()
      ->getResult();
    $lista_classi = $em->getRepository('App:Classe')->createQueryBuilder('c')
      ->orderBy('c.anno,c.sezione', 'ASC')
      ->getQuery()
      ->getResult();
    $label_docenti = $trans->trans('label.tutti_docenti');
    $label_classi = $trans->trans('label.tutte_classi');
    // form
    $form = $this->createForm(ModuloType::class, null, ['formMode' => 'archivia',
      'dati' => [$lista_docenti, $lista_sostegno, $lista_classi, $label_docenti, $label_classi]]);
    $form->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
      // form inviato
      $docente = $form->get('docente')->getData();
      $sostegno = $form->get('sostegno')->getData();
      $classe = $form->get('classe')->getData();
      $scrutinio = $form->get('scrutinio')->getData();
      $circolare = ($form->get('circolare')->getData() === true);
      // assicura che lo script non sia interrotto
      ini_set('max_execution_time', 0);
      // registro docenti
      if (is_object($docente)) {
        // crea registro
        $arch->registroDocente($docente);
      } elseif ($docente === -1) {
        // crea tutti i registri
        $arch->tuttiRegistriDocente($lista_docenti);
      }
      // registro sostegno
      if (is_object($sostegno)) {
        // crea registro
        $arch->registroSostegno($sostegno);
      } elseif ($sostegno === -1) {
        // crea tutti i registri
        $arch->tuttiRegistriSostegno($lista_sostegno);
      }
      // registro classe
      if (is_object($classe)) {
        // crea registro
        $arch->registroClasse($classe);
      } elseif ($classe === -1) {
        // crea tutti i registri
        $arch->tuttiRegistriClasse($lista_classi);
      }
      // documenti scrutinio
      if (is_object($scrutinio)) {
        // crea documenti per la classe
        $arch->scrutinioClasse($scrutinio);
      } elseif ($scrutinio === -1) {
        // crea documenti per tutte le classi
        $arch->tuttiScrutiniClasse($lista_classi);
      }
      // archivio circolari
      if ($circolare) {
        // crea archivio delle circolari
        $arch->archivioCircolari();
      }
    }
    // mostra la pagina di risposta
    return $this->renderHtml('sistema', 'archivia', $dati, $info, [$form->createView(), 'message.archivia']);
  }

}
