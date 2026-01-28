<?php

function sistema_menu($modulo,$interfaz,$origen) 
        {    
          $conn      = conexionSQL();
          $Global      = new ModelGlobal();
          $sql         = "SELECT * FROM  gb_modulo WHERE gb_estatus = '1' and gb_id_modulo in(22,23) ORDER BY gb_id_modulo  ASC";
          $resultado   = sqlsrv_query($conn, $sql);// $mysqli->query($sql);


          while ($fila = sqlsrv_fetch_array($resultado, SQLSRV_FETCH_ASSOC)) {
            /** PERFIL ADMINISTRADOR **/
              $validacion = $Global->modulo_permitido($fila['gb_id_modulo'], $_SESSION["gb_perfil"]) == 1;

             if($validacion)
            {
        ?> 

    <?php
    if($modulo == 0)
                  {  
            ?>  

<div class="menu_section">
							<!--<h3>General</h3>-->
							<ul class="nav side-menu">
								<li><a><i class="<?php echo  $fila['gb_icono_modulo']; ?>"></i> 
                <?php echo  $fila['gb_nombre_modulo']; ?><span
											class="fa fa-chevron-down"></span></a>
									


        <?php
              }
              else
              {

             ?>

<div class="menu_section">
							<!--<h3>General</h3>-->
							<ul class="nav side-menu">
								<li><a><i class="<?php echo  $fila['gb_icono_modulo']; ?>"></i> 
                <?php echo  $fila['gb_nombre_modulo']; ?><span
											class="fa fa-chevron-down"></span></a>
        <?php
              }

              ?> 
            <!-- CARGAMOS EL MENU -->
            <ul class="nav child_menu">
  

            <?php
               

               $sql_menu         = "SELECT * FROM  gb_menu WHERE gb_id_modulo  ='".$fila['gb_id_modulo']."' AND gb_estatus='1' order by gb_id_menu asc";
			   
            

               $resultado_menu   = sqlsrv_query($conn, $sql_menu);// $mysqli->query($sql);
        
          
               while ($fila_menu = sqlsrv_fetch_array($resultado_menu, SQLSRV_FETCH_ASSOC)) {

                if($origen == 0)
                {
                    
                    $ext = $fila_menu['gb_raiz'].'/';
                }
                else
                {
                    $ext = '';
                }
                if($interfaz == $fila_menu['gb_id_menu'])
                { 
             ?>

<li class="current-page" style="background-color:orange"><a    href="./index.php?opc=<?php echo  $ext.$fila_menu['gb_archivo']; ?>"><?php echo  $fila_menu['gb_nombre_menu']; ?></a></li>

              <?php
                }
                else
                {
 
                
             ?>
 <li><a  href="./index.php?opc=<?php echo  $ext.$fila_menu['gb_archivo']; ?>"><?php echo  $fila_menu['gb_nombre_menu']; ?></a></li>

             
             <?php
                }

               }
            ?>

</ul>
								</li>
							</ul>
						</div>


        <?php
              } else {
              }
          }


        }
        ?>