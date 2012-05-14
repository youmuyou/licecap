<?

/*
This seems to do the trick:
----
BITS 64
SECTION .text
	global _asm_generic1parm
_asm_generic1parm:
	fld qword [rax]
	mov rax,[qword 0xFEFEFEFEFEFEFEFE]
	global _asm_generic1parm_end
_asm_generic1parm_end:


todo: make this generate that. obviously hell will be here soon.
*/


function makeregs64(&$parms)
{

	  $parms = str_replace("ebp","rbp",$parms);
	  $parms = str_replace("esp","rsp",$parms);
	  $parms = str_replace("esi","rsi",$parms);
	  $parms = str_replace("edi","rdi",$parms);
	  $parms = str_replace("eax","rax",$parms);
	  $parms = str_replace("ebx","rbx",$parms);
	  $parms = str_replace("ecx","rcx",$parms);
	  $parms = str_replace("edx","rdx",$parms);
  return $parms;
}


function process_file($infn, $outfn, $pretext)
{

$in = fopen($infn,"r");
if (!$in) die("error opening input $infn\n");
$out = fopen($outfn,"w");
if (!$out) die("error opening output $outfn\n");

fputs($out,"; THIS FILE AUTOGENERATED FROM $infn by a2x64.php\n\n");
if ($pretext!="") fputs($out,$pretext);
$inblock=0;

$labelcnt=0;
$ignoring=0;

fputs($out,"%define EEL_F_SIZE 8\n");

fputs($out,"%define TARGET_X64\n");
fputs($out,"SECTION .text\n");

while (($line = fgets($in)))
{
  $line = rtrim($line);
  $nowrite=0;
  if (substr(trim($line),0,1) == '#')
  {
     $line = str_replace("defined(__APPLE__)","0",$line);
     $line=preg_replace("/(.*)\\/\\*(.*)\\*\\/(.*)/","$1$3 ; $2",$line);
     $line=preg_replace("/(.*)\\/\\/(.*)/","$1 ; $2",$line);
     fputs($out,"%" . substr(trim($line),1) . "\n");
     continue;
  }
  
  $line=preg_replace("/(.*)\\/\\*(.*)\\*\\/(.*)/","$1$3 ; $2",$line);
  $line=preg_replace("/(.*)\\/\\/(.*)/","$1 ; $2",$line);
  {
    if (!$inblock)
    {
      if (substr(trim($line),0,5)== "void ")
      {
        global $want_funclead;
        $x=preg_replace("/void (.+)\\(.*/","$1",$line);
        fputs($out, "\n\nglobal $want_funclead$x\n$want_funclead$x:\n");
      }
      if (!$ignoring && strstr($line,"__asm__("))
      {
        $line="";
        $inblock=1;
        if (isset($bthist)) unset($bthist);
        if (isset($btfut)) unset($btfut);
        $bthist = array();
        $btfut = array();
      }
    }

    if ($inblock)
    {
      if (substr(trim($line),-2) == ");") 
      {
        fputs($out,"db 0x89");
        for ($tmp=0;$tmp<11;$tmp++) fputs($out,",0x90");
        fputs($out,"\n");
        $line = substr(trim($line),0,-2);
	fputs($out,$line);
        $inblock=0;
      }

      $sline = strstr($line, "\"");
      $lastchunk = strrchr($line,"\"");
      if ($sline && $lastchunk && strlen($sline) != strlen($lastchunk))
      {
        $beg_restore = substr($line,0,-strlen($sline));

        if (strlen($lastchunk)>1)
           $end_restore = substr($line,1-strlen($lastchunk));
        else $end_restore="";

        $sline = substr($sline,1,strlen($sline)-1-strlen($lastchunk));

        // get rid of chars we can ignore
        $sline=str_replace("\\n","", $sline);
        $sline=str_replace("\"","", $sline);
        $sline=str_replace("$","", $sline);
        $sline=str_replace("%","", $sline);


        // get rid of excess whitespace, especially around commas
        $sline=str_replace("  "," ", $sline);
        $sline=str_replace("  "," ", $sline);
        $sline=str_replace("  "," ", $sline);
        $sline=str_replace(", ",",", $sline);
        $sline=str_replace(" ,",",", $sline);

        $sline=preg_replace("/st\\(([0-9]+)\\)/","FPREG_$1",$sline);


        if (preg_match("/^([0-9]+):/",trim($sline)))
        {
           $d = (int) $sline;
           $a = strstr($sline,":");
           if ($a) $sline = substr($a,1);

           if (isset($btfut[$d]) && $btfut[$d] != "") $thislbl = $btfut[$d]; 
           else $thislbl = "label_" . $labelcnt++;

           $btfut[$d]="";
           $bthist[$d] = $thislbl;

           fputs($out,$thislbl . ":\n");
        }
        
        $sploded = explode(" ",trim($sline));
        if ($sline != "" && count($sploded)>0)
        {        
          $inst = trim($sploded[0]);
          $suffix = "";

          $instline = strstr($sline,$inst); 
          $beg_restore .= substr($sline,0,-strlen($instline));

          $parms = trim(substr($instline,strlen($inst)));

          if ($inst=="j") $inst="jmp";

//          if ($inst == "fdiv" && $parms == "") $inst="fdivr";

          if ($inst != "call" && substr($inst,-2) == "ll") $suffix = "ll";
          else if ($inst != "call" && $inst != "fmul" && substr($inst,-1) == "l") $suffix = "l";
          else if (substr($inst,0,1)=="f" && $inst != "fcos" && $inst != "fsincos" && $inst != "fchs" && $inst != "fabs" && substr($inst,-1) == "s") $suffix = "s";


          if ($suffix != "" && $inst != "jl") $inst = substr($inst,0,-strlen($suffix));

          $parms = preg_replace("/\\((.{2,3}),(.{2,3})\\)/","($1+$2)",$parms);

          $parms=preg_replace("/EEL_F_SUFFIX (-?[0-9]+)\\((.*)\\)/","qword [$2+$1]",$parms);
          $parms=preg_replace("/EEL_F_SUFFIX \\((.*)\\)/","qword [$1]",$parms);

          if ($inst == "sh" && $suffix == "ll") { $suffix="l"; $inst="shl"; }
          
          if ($suffix == "ll" || ($suffix == "l" && substr($inst,0,1) == "f" && substr($inst,0,2) != "fi")) $suffixstr = "qword ";
          else if ($suffix == "l") $suffixstr = "dword ";
          else if ($suffix == "s") $suffixstr = "dword ";
          else $suffixstr = "";
          $parms=preg_replace("/([0-9]+)\\((.*)\\)/",$suffixstr . "[$2+$1]",$parms);
          $parms=preg_replace("/\\((.*)\\)/",$suffixstr . "[$1]",$parms);


          $parms=str_replace("NSEEL_LOOPFUNC_SUPPORT_MAXLEN_STR","10000000", $parms);
          $parms=str_replace("EEL_F_SUFFIX","qword", $parms);
          $parms=str_replace("EEL_F_SSTR","8", $parms);

          $plist = explode(",",$parms);
          if (count($plist) > 2) echo "Warning: too many parameters $parms!\n";
          else if (count($plist)==2) 
          {
		if ($suffixstr != "dword " || strstr($plist[0],"[")) 
                  makeregs64($plist[0]);
		if ($suffixstr != "dword " || stristr($plist[0],"ffffffff") || strstr($plist[1],"[")) makeregs64($plist[1]);

               if (!stristr($plist[0],"[") &&
                   !stristr($plist[1],"[")) { makeregs64($plist[1]); makeregs64($plist[0]); }

               $parms = trim($plist[1]) . ", " . trim($plist[0]);
          }
          else
          {
	//	if ($suffixstr != "dword ") 
             makeregs64($parms);
          }

          if ($inst=="fsts") $inst="fstsw";
          if ($inst=="fistp") $inst="fisttp";
          if ($inst=="call" && substr($parms,0,1) == "*") $parms=substr($parms,1);
          if (substr($inst,0,1) == "j") 
          {
            if (substr($parms,-1) == "f")
            {
              $d = (int) substr($parms,0,-1);
              if (isset($btfut[$d]) && $btfut[$d] != "") $thislbl = $btfut[$d]; 
              else $btfut[$d] = $thislbl = "label_" . $labelcnt++;
              $parms = $thislbl;
            }
            else if (substr($parms,-1) == "b")
            {
              $d = (int) substr($parms,0,-1);
              if ($bthist[$d]=="") echo "Error resolving label $parms\n";
              $parms = $bthist[$d];
            }
          }
          $parms = preg_replace("/0x[fe,FE]{8}/","qword 0xFEFEFEFEFEFEFEFE",$parms);

          

          $sline = $inst;
          if ($parms !="") $sline .= " "  . $parms;

        }

        $sline=preg_replace("/FPREG_([0-9]+)/","st$1",$sline);
        $line = $beg_restore . $sline . $end_restore;

      }


    }
  }

  if ($inblock)
    fputs($out,$line . "\n");
}
 
if ($inblock) echo "Error (ended in __asm__ block???)\n";


fclose($in);
fclose($out);

};

$nasm="nasm";
$want_funclead="";

$fmt = "win64";
if ($argv[1] != "") $fmt = $argv[1];

$fnout = "asm-nseel-x64.asm";

if ($fmt == "macho64") {  $fnout="asm-nseel-x64-macho.asm"; $nasm = "nasm64"; $want_funclead = "_"; }
if ($fmt == "macho64x") {  $fnout="asm-nseel-x64-macho.asm"; $nasm = "nasm"; $want_funclead = "_"; $fmt="macho64"; }
if ($fmt == "win64x") { $nasm="nasm64"; $fmt = "win64"; }

process_file("asm-nseel-x86-gcc.c" , $fnout, $fmt != "win64" ? "%define AMD64ABI\n" : "");


system("$nasm -f $fmt $fnout");

?>
