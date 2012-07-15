This is a mostly complete port of [pyPdf](http://pybrary.net/pyPdf/). The API is basically the same.

    <?php
    
    include 'pdf-parser/pdf.php';
    
    $pdf = new PdfFileReader(fopen('test.pdf', 'rb'));
    print $pdf->page_count;
    
    ?>
