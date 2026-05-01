<?php
ob_start();
include 'room_modal.php';
$modalContent = ob_get_clean();
$modalContent = str_replace('href="../../css/style_modal.css"', 'href="css/style_modal.css"', $modalContent);
$modalContent = str_replace("fetch('../../create_room.php'", "fetch('create_room.php'", $modalContent);
?>
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" type="image/png" href="img/favicon.png">
    <title>WIREOG: Secure Wire</title>

    <script src="assets/crypto-js.min.js"></script>

    <style>
    </style>

</head>

<body>

    <div class="center-image">
        <img src="img/WIREOG.png" alt="WIREOG:Secure Messenger Web">
    </div>

    <div class="slogan-container">
        <h3 class="slogan-title">
            <span class="slogan-wire">Wire</span><span class="slogan-og">OG</span>
        </h3>
        <p class="slogan-subtitle">
            Lightweight, private messaging
        </p>
        <p class="slogan-tagline">
            your data, your control
        </p>
        <div class="slogan-underline"></div>
    </div>

    <div class="button-myroom">
        <button id="create-room-btn">My Rooms</button>
    </div>
    
    <section id="create-room-button">

        
        <h2>Create a new secret room</h2>
        <h3>No registration required, no email, no phone number. Just a click and your room is created.</h3>

        <p><strong>Important:</strong> Rooms without a password may be decrypted by anyone who knows the room name and has sufficient technical skills.

        Password protected rooms use end to end encryption. The password never reaches the server, and only participants who know it can send and read the messages.</p>
        <p>To enhance security when sharing sensitive URLs, you can use our secure sharing tool. <br>This tool generates a protected link with a view counter, allowing you to define the maximum number of times the content can be accessed.</p>
        <p><a href="../secureshare" target="_blank" rel="noopener noreferrer">🔒 Secure URL Sharing Tool</a></p><br><br>
    </section>


    <div class="developer-message">
        <h2>A Message from The Architect</h2>
        <h3>Welcome to <b>WireOG</b> - the realization of a vision long suppressed by the tech industry.</h3>
        <p>Today, I'm proud to present you with the OG version - the <b>OG</b> of messaging. At just
            <b>45 kilobytes</b> this service embodies: <b>complete privacy</b>, total
            user control, and <b>accessibility</b> for all.</p>
        <p>With WireOG, you can exchange <b>text</b>, <b>voice</b>, <b>images</b>, and <b>files</b>, all while retaining
            full command over your data. Our unique "secret rooms" feature allows every participant to manage and if
            necessary completely erase conversation histories from server.</p>
        <p>This is more than just a messaging service - it's a statement. It's proof that we can communicate freely and
            securely without sacrificing our privacy or becoming products ourselves.</p>
        <p>I offer this to you, free of charge, as a testament to what's possible when we prioritize user rights over
            corporate interests. Use it wisely, spread the word, and let's reclaim our digital autonomy together.</p>
        <p>Welcome to the real future of messaging - welcome to WireOG.</p>
    </div>

    <footer class="footer">
        <div class="center-image">
            <img src="img/favicon.png" alt="WIREOG:Secure Messenger Web">
        </div>
        <div class="footer-content">
            <div class="footer-section">
                <h4>Disclaimer</h4>
                <p>WireOG is provided "as is" without warranty of any kind. Use of this service is at your own risk.
                    While we strive to ensure the highest level of privacy and security, we cannot guarantee absolute
                    protection against all potential threats. Users are responsible for their own actions and the
                    content they share.</p>
            </div>
            <div class="footer-section">
                <h4>Copyright Notice</h4>
                <p>© 2026 WireOG. All rights reserved. The WireOG software is open source and released under the MIT
                    License. You are free to use, modify, and distribute the code, subject to the license terms. The
                    WireOG name, logo, and website content are proprietary and may not be used without express
                    permission.</p>
            </div>
            <div class="copyright">
                <p class="footerArc">Designed by The Architect. Committed to digital privacy and user empowerment.</p>
            </div>
        </div>
    </footer>

    <?= $modalContent ?>

<script>
    document
        .getElementById('create-room-btn')
        .addEventListener('click', openRoomModal);
</script>

<script src="/nodepulse-sw.js"></script>
</body>

</html>
