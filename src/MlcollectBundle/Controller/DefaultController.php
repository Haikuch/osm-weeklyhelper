<?php

namespace MlcollectBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends Controller {
    
    public function indexAction() {
        
        $datas = $this->get('mlcollect.collect')->collect();
        
        return $this->render('MlcollectBundle:Default:index.html.twig', ['datas' => $datas]);
    }
}
