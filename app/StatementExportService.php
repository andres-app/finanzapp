<?php
/**
 * Estado de cuenta amigable sin dependencias externas.
 * - PDF: generador PDF mínimo con fuentes estándar.
 * - Excel: SpreadsheetML 2003 (.xls), compatible con Excel/LibreOffice.
 */
class StatementExportService {
    public static function build(int $userId, string $period, int $accountId = 0): array {
        FinanceSchema::ensure($userId);
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
        [$start,$end] = month_range($period);

        $accountsStmt = db()->prepare('SELECT id,name,account_type,opening_balance FROM financial_accounts WHERE user_id=? AND active=1 ORDER BY id');
        $accountsStmt->execute([$userId]);
        $accounts = $accountsStmt->fetchAll();
        $accountMap=[];foreach($accounts as $a)$accountMap[(int)$a['id']]=$a;
        if ($accountId > 0 && !isset($accountMap[$accountId])) throw new RuntimeException('La cuenta seleccionada no existe.');

        $selected = $accountId > 0 ? $accountMap[$accountId] : null;
        $opening = self::openingBalance($userId,$start,$accountId,$accounts);
        $events = self::events($userId,$start,$end,$accountId,$accountMap);

        $running=$opening;$credits=0.0;$debits=0.0;$income=0.0;$expense=0.0;
        foreach($events as &$ev){
            $delta=(float)$ev['delta'];
            $running += $delta;
            $ev['balance']=round($running,2);
            if($delta>=0)$credits+=$delta;else$debits+=abs($delta);
            if($ev['kind']==='income')$income+=(float)$ev['amount'];
            if($ev['kind']==='expense')$expense+=(float)$ev['amount'];
        }unset($ev);

        $months=['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
        [$yy,$mm]=explode('-',$period);
        $periodLabel=($months[$mm]??$mm).' '.$yy;

        return [
            'period'=>$period,'period_label'=>$periodLabel,
            'account_id'=>$accountId,'account_name'=>$selected['name']??'Todas las cuentas',
            'is_consolidated'=>$accountId<=0,'opening_balance'=>round($opening,2),'closing_balance'=>round($running,2),
            'credits'=>round($credits,2),'debits'=>round($debits,2),'income'=>round($income,2),'expense'=>round($expense,2),
            'movement_count'=>count($events),'events'=>$events,'accounts'=>$accounts,
            'generated_at'=>date('d/m/Y H:i'),
        ];
    }

    private static function openingBalance(int $userId,string $start,int $accountId,array $accounts): float {
        $opening=0.0;
        if($accountId>0){
            foreach($accounts as $a)if((int)$a['id']===$accountId){$opening=(float)$a['opening_balance'];break;}
            $q=db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM transactions WHERE user_id=? AND account_id=? AND voided_at IS NULL AND occurred_at<?");
            $q->execute([$userId,$accountId,$start]);$opening+=(float)$q->fetchColumn();
            $q=db()->prepare("SELECT COALESCE(SUM(amount),0) FROM account_adjustments WHERE user_id=? AND account_id=? AND voided_at IS NULL AND occurred_at<?");
            $q->execute([$userId,$accountId,$start]);$opening+=(float)$q->fetchColumn();
            $q=db()->prepare("SELECT COALESCE(SUM(CASE WHEN to_account_id=? THEN amount WHEN from_account_id=? THEN -amount ELSE 0 END),0) FROM account_transfers WHERE user_id=? AND voided_at IS NULL AND occurred_at<? AND (from_account_id=? OR to_account_id=?)");
            $q->execute([$accountId,$accountId,$userId,$start,$accountId,$accountId]);$opening+=(float)$q->fetchColumn();
            return $opening;
        }
        foreach($accounts as $a)$opening+=(float)$a['opening_balance'];
        $q=db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM transactions WHERE user_id=? AND voided_at IS NULL AND occurred_at<?");
        $q->execute([$userId,$start]);$opening+=(float)$q->fetchColumn();
        $q=db()->prepare("SELECT COALESCE(SUM(amount),0) FROM account_adjustments WHERE user_id=? AND voided_at IS NULL AND occurred_at<?");
        $q->execute([$userId,$start]);$opening+=(float)$q->fetchColumn();
        // Transferencias internas no cambian el patrimonio consolidado.
        return $opening;
    }

    private static function events(int $userId,string $start,string $end,int $accountId,array $accountMap): array {
        $out=[];
        $sql="SELECT t.id,t.type,t.amount,t.occurred_at,t.description,c.name category,co.name concept,a.name account,u.name actor
            FROM transactions t JOIN categories c ON c.id=t.category_id LEFT JOIN concepts co ON co.id=t.concept_id
            LEFT JOIN financial_accounts a ON a.id=t.account_id LEFT JOIN users u ON u.id=COALESCE(t.created_by_user_id,t.user_id)
            WHERE t.user_id=? AND t.voided_at IS NULL AND t.occurred_at>=? AND t.occurred_at<?";
        $params=[$userId,$start,$end];
        if($accountId>0){$sql.=' AND t.account_id=?';$params[]=$accountId;}
        $q=db()->prepare($sql);$q->execute($params);
        foreach($q->fetchAll() as $r){
            $amt=(float)$r['amount'];$income=$r['type']==='income';
            $base=trim((string)($r['concept']?:$r['category']));
            $note=trim((string)($r['description']??''));
            $out[]=['sort'=>'1-'.str_pad((string)$r['id'],16,'0',STR_PAD_LEFT),'occurred_at'=>$r['occurred_at'],'kind'=>$income?'income':'expense','type_label'=>$income?'Ingreso':'Gasto','account'=>$r['account']?:'Cuenta','title'=>$base?:($income?'Ingreso':'Gasto'),'detail'=>$note,'actor'=>$r['actor']?:'Usuario','amount'=>$amt,'delta'=>$income?$amt:-$amt];
        }

        $sql="SELECT ad.id,ad.amount,ad.occurred_at,ad.note,a.name account,u.name actor FROM account_adjustments ad JOIN financial_accounts a ON a.id=ad.account_id LEFT JOIN users u ON u.id=COALESCE(ad.created_by_user_id,ad.user_id) WHERE ad.user_id=? AND ad.voided_at IS NULL AND ad.occurred_at>=? AND ad.occurred_at<?";
        $params=[$userId,$start,$end];if($accountId>0){$sql.=' AND ad.account_id=?';$params[]=$accountId;}
        $q=db()->prepare($sql);$q->execute($params);
        foreach($q->fetchAll() as $r){$amt=(float)$r['amount'];$out[]=['sort'=>'2-'.str_pad((string)$r['id'],16,'0',STR_PAD_LEFT),'occurred_at'=>$r['occurred_at'],'kind'=>'adjustment','type_label'=>'Ajuste','account'=>$r['account'],'title'=>'Ajuste de saldo','detail'=>trim((string)($r['note']?:'Conciliación de saldo')),'actor'=>$r['actor']?:'Usuario','amount'=>abs($amt),'delta'=>$amt];}

        if($accountId>0){
            $q=db()->prepare("SELECT tr.id,tr.from_account_id,tr.to_account_id,tr.amount,tr.occurred_at,tr.description,a1.name from_name,a2.name to_name,u.name actor FROM account_transfers tr JOIN financial_accounts a1 ON a1.id=tr.from_account_id JOIN financial_accounts a2 ON a2.id=tr.to_account_id LEFT JOIN users u ON u.id=COALESCE(tr.created_by_user_id,tr.user_id) WHERE tr.user_id=? AND tr.voided_at IS NULL AND tr.occurred_at>=? AND tr.occurred_at<? AND (tr.from_account_id=? OR tr.to_account_id=?)");
            $q->execute([$userId,$start,$end,$accountId,$accountId]);
            foreach($q->fetchAll() as $r){
                $incoming=(int)$r['to_account_id']===$accountId;$amt=(float)$r['amount'];
                $out[]=['sort'=>'3-'.str_pad((string)$r['id'],16,'0',STR_PAD_LEFT),'occurred_at'=>$r['occurred_at'],'kind'=>'transfer','type_label'=>$incoming?'Transferencia recibida':'Transferencia enviada','account'=>$incoming?$r['to_name']:$r['from_name'],'title'=>$incoming?'Transferencia desde '.$r['from_name']:'Transferencia a '.$r['to_name'],'detail'=>trim((string)($r['description']?:'Movimiento entre cuentas')),'actor'=>$r['actor']?:'Usuario','amount'=>$amt,'delta'=>$incoming?$amt:-$amt];
            }
        }
        usort($out,function($a,$b){$c=strcmp($a['occurred_at'],$b['occurred_at']);return $c!==0?$c:strcmp($a['sort'],$b['sort']);});
        return $out;
    }

    public static function pdf(array $data,string $ownerName=''): string {
        $pdf=new SimpleFinancePdf();
        $rows=$data['events'];$index=0;$page=0;
        do{
            $pdf->addPage();$page++;
            self::pdfHeader($pdf,$data,$ownerName,$page);
            $y=174;
            if($page===1){self::pdfSummary($pdf,$data);$y=250;}
            self::pdfTableHeader($pdf,$y);$y+=24;
            $perPage=$page===1?14:18;$taken=0;
            while($index<count($rows)&&$taken<$perPage){$r=$rows[$index++];self::pdfRow($pdf,$r,$y,$taken%2===1);$y+=18;$taken++;}
            if(!$rows && $page===1){$pdf->text(54,$y+22,'No hay movimientos registrados en este periodo.',10,'',0.35,0.39,0.46);}
            $pdf->text(54,560,'Generado '.$data['generated_at'].' · Mi Dinero',8,'',0.45,0.48,0.54);
            $pdf->text(744,560,'Pagina '.$page,8,'',0.45,0.48,0.54,'R');
        }while($index<count($rows));
        return $pdf->output();
    }

    private static function pdfHeader(SimpleFinancePdf $pdf,array $d,string $owner,int $page): void {
        $pdf->rect(0,0,842,112,0.055,0.082,0.137,true);
        $pdf->text(54,40,'MI DINERO',10,'B',0.58,0.98,0.78);
        $pdf->text(54,66,'Estado de cuenta',25,'B',1,1,1);
        $pdf->text(54,89,$d['account_name'].' · '.$d['period_label'],10,'',0.82,0.85,0.9);
        if($owner!=='')$pdf->text(788,53,$owner,9,'B',0.92,0.94,0.97,'R');
        if($page>1)$pdf->text(788,75,'Continuacion',9,'',0.72,0.76,0.82,'R');
    }
    private static function pdfSummary(SimpleFinancePdf $pdf,array $d): void {
        $cards=[['Saldo inicial',$d['opening_balance']],['Entradas',$d['credits']],['Salidas',$d['debits']],['Saldo final',$d['closing_balance']]];
        $x=54;foreach($cards as $i=>$c){$w=171;$pdf->rect($x,132,$w,80,0.96,0.965,0.97,true);$pdf->text($x+14,154,$c[0],8,'',0.43,0.47,0.53);$pdf->text($x+14,181,self::money($c[1]),17,'B',$i===3?0.03:0.08,$i===3?0.45:0.10,$i===3?0.29:0.16);$x+=$w+8;}
        $pdf->text(54,230,$d['movement_count'].' movimientos · Ingresos registrados '.self::money($d['income']).' · Gastos registrados '.self::money($d['expense']),8,'',0.42,0.45,0.51);
    }
    private static function pdfTableHeader(SimpleFinancePdf $pdf,float $y): void {
        $pdf->rect(54,$y,734,22,0.93,0.94,0.95,true);
        $pdf->text(62,$y+15,'FECHA',8,'B',0.28,0.31,0.36);
        $pdf->text(132,$y+15,'MOVIMIENTO',8,'B',0.28,0.31,0.36);
        $pdf->text(512,$y+15,'ENTRADA',8,'B',0.28,0.31,0.36,'R');
        $pdf->text(616,$y+15,'SALIDA',8,'B',0.28,0.31,0.36,'R');
        $pdf->text(780,$y+15,'SALDO',8,'B',0.28,0.31,0.36,'R');
    }
    private static function pdfRow(SimpleFinancePdf $pdf,array $r,float $y,bool $alt): void {
        if($alt)$pdf->rect(54,$y,734,18,0.985,0.985,0.982,true);
        $pdf->text(62,$y+12,date('d/m/Y',strtotime($r['occurred_at'])),8,'',0.24,0.27,0.32);
        $detail=$r['title'];if($r['detail']!=='')$detail.=' - '.$r['detail'];if($r['account']!=='')$detail.=' · '.$r['account'];
        $pdf->text(132,$y+12,self::clip($detail,68),8,'',0.14,0.16,0.2);
        $delta=(float)$r['delta'];
        if($delta>=0)$pdf->text(512,$y+12,self::money($delta),8,'B',0.05,0.45,0.29,'R');
        else $pdf->text(616,$y+12,self::money(abs($delta)),8,'B',0.76,0.21,0.16,'R');
        $pdf->text(780,$y+12,self::money($r['balance']),8,'B',0.10,0.12,0.16,'R');
        $pdf->line(54,$y+18,788,$y+18,0.91,0.92,0.93,0.5);
    }
    private static function money(float $n): string {return 'S/ '.number_format($n,2,'.',',');}
    private static function clip(string $s,int $len): string {if(function_exists('mb_strlen'))return mb_strlen($s)>$len?mb_substr($s,0,$len-1).'…':$s;return strlen($s)>$len?substr($s,0,$len-3).'...':$s;}

    public static function excelXml(array $data,string $ownerName=''): string {
        $x=function($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_XML1,'UTF-8');};
        $num=function($v){return number_format((float)$v,2,'.','');};
        $xml='<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?>';
        $xml.='<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $xml.='<Styles><Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Aptos" ss:Size="10"/></Style><Style ss:ID="Title"><Font ss:Bold="1" ss:Size="18" ss:Color="#111827"/></Style><Style ss:ID="Head"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#111827" ss:Pattern="Solid"/></Style><Style ss:ID="Label"><Font ss:Bold="1" ss:Color="#6B7280"/></Style><Style ss:ID="Money"><NumberFormat ss:Format="&quot;S/ &quot;#,##0.00;[Red]-&quot;S/ &quot;#,##0.00"/></Style><Style ss:ID="MoneyBold"><Font ss:Bold="1"/><NumberFormat ss:Format="&quot;S/ &quot;#,##0.00;[Red]-&quot;S/ &quot;#,##0.00"/></Style><Style ss:ID="Date"><NumberFormat ss:Format="dd/mm/yyyy hh:mm"/></Style><Style ss:ID="Muted"><Font ss:Color="#6B7280"/></Style></Styles>';
        $xml.='<Worksheet ss:Name="Resumen"><Table><Column ss:Width="165"/><Column ss:Width="190"/>';
        $xml.='<Row ss:Height="30"><Cell ss:MergeAcross="1" ss:StyleID="Title"><Data ss:Type="String">Estado de cuenta</Data></Cell></Row>';
        $summary=[['Periodo',$data['period_label']],['Cuenta',$data['account_name']],['Titular / hogar',$ownerName?:'Mi Dinero'],['Saldo inicial',$data['opening_balance'],'n'],['Entradas',$data['credits'],'n'],['Salidas',$data['debits'],'n'],['Saldo final',$data['closing_balance'],'n'],['Movimientos',$data['movement_count']],['Generado',$data['generated_at']]];
        foreach($summary as $r){$xml.='<Row><Cell ss:StyleID="Label"><Data ss:Type="String">'.$x($r[0]).'</Data></Cell>';if(($r[2]??'')==='n')$xml.='<Cell ss:StyleID="MoneyBold"><Data ss:Type="Number">'.$num($r[1]).'</Data></Cell>';else $xml.='<Cell><Data ss:Type="String">'.$x($r[1]).'</Data></Cell>';$xml.='</Row>';}
        $xml.='</Table></Worksheet>';
        $xml.='<Worksheet ss:Name="Movimientos"><Table><Column ss:Width="105"/><Column ss:Width="135"/><Column ss:Width="125"/><Column ss:Width="290"/><Column ss:Width="90"/><Column ss:Width="90"/><Column ss:Width="95"/>';
        $headers=['Fecha','Cuenta','Tipo','Detalle','Entrada','Salida','Saldo'];$xml.='<Row>';foreach($headers as $h)$xml.='<Cell ss:StyleID="Head"><Data ss:Type="String">'.$x($h).'</Data></Cell>';$xml.='</Row>';
        foreach($data['events'] as $r){$delta=(float)$r['delta'];$detail=$r['title'].($r['detail']!==''?' - '.$r['detail']:'').($r['actor']!==''?' · '.$r['actor']:'');$iso=date('Y-m-d\TH:i:s.000',strtotime($r['occurred_at']));$xml.='<Row><Cell ss:StyleID="Date"><Data ss:Type="DateTime">'.$iso.'</Data></Cell><Cell><Data ss:Type="String">'.$x($r['account']).'</Data></Cell><Cell><Data ss:Type="String">'.$x($r['type_label']).'</Data></Cell><Cell><Data ss:Type="String">'.$x($detail).'</Data></Cell><Cell ss:StyleID="Money"><Data ss:Type="Number">'.($delta>=0?$num($delta):'0').'</Data></Cell><Cell ss:StyleID="Money"><Data ss:Type="Number">'.($delta<0?$num(abs($delta)):'0').'</Data></Cell><Cell ss:StyleID="MoneyBold"><Data ss:Type="Number">'.$num($r['balance']).'</Data></Cell></Row>';}
        $xml.='</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal><TopRowBottomPane>1</TopRowBottomPane><ProtectObjects>False</ProtectObjects><ProtectScenarios>False</ProtectScenarios></WorksheetOptions></Worksheet>';
        $xml.='</Workbook>';
        return $xml;
    }
}

class SimpleFinancePdf {
    private array $pages=[];
    public function addPage(): void {$this->pages[]='';}
    private function &page(): string {if(!$this->pages)$this->addPage();$i=count($this->pages)-1;return $this->pages[$i];}
    private function enc(string $s): string {
        $s=preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u','',$s)??$s;
        $c=@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$s);if($c===false)$c=preg_replace('/[^\x20-\x7E]/','?',$s)??$s;
        return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$c);
    }
    public function text(float $x,float $y,string $text,float $size=10,string $weight='',float $r=0,float $g=0,float $b=0,string $align='L'): void {
        $font=$weight==='B'?'F2':'F1';$safe=$this->enc($text);$width=strlen($safe)*$size*0.50;if($align==='R')$x-=$width;if($align==='C')$x-=$width/2;
        $py=595-$y;$p=&$this->page();$p.=sprintf("BT /%s %.2f Tf %.3f %.3f %.3f rg 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n",$font,$size,$r,$g,$b,$x,$py,$safe);
    }
    public function rect(float $x,float $y,float $w,float $h,float $r,float $g,float $b,bool $fill=true): void {$py=595-$y-$h;$p=&$this->page();$p.=sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re %s\n",$r,$g,$b,$x,$py,$w,$h,$fill?'f':'S');}
    public function line(float $x1,float $y1,float $x2,float $y2,float $r,float $g,float $b,float $width=1): void {$p=&$this->page();$p.=sprintf("%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n",$r,$g,$b,$width,$x1,595-$y1,$x2,595-$y2);}
    public function output(): string {
        $objects=[];$objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$kids=[];$next=5;
        foreach($this->pages as $i=>$content){$pageObj=$next++;$streamObj=$next++;$kids[]=$pageObj.' 0 R';$objects[$pageObj]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$streamObj.' 0 R >>';$objects[$streamObj]='<< /Length '.strlen($content).' >>' . "\nstream\n".$content."endstream";}
        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';$objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';$objects[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';ksort($objects);
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0=>0];foreach($objects as $n=>$obj){$offsets[$n]=strlen($pdf);$pdf.=$n." 0 obj\n".$obj."\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]??0);$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";return $pdf;
    }
}
