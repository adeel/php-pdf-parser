This is a mostly complete port of pyPdf &lt;http://pybrary.net/pyPdf/&gt;. The API is basically the same.

    <?php
    
    include 'pdf-parser/pdf.php';
    
    $pdf = PdfFileReader(fopen('test.pdf', 'rb'));
    print $pdf->page_count;
    
    ?>
