<p align="center">
  <img src="http://i.imgur.com/leEDvlZ.png" />
</p>
  
##What is it?  
2StepAuth (2 step authorization) is a MyBB plugin created as a extra security layer on top of the normal login procedure.  
It uses the Google Authenticator app for the creation of authorization codes.  
Alternatively, emails can also be used for users without a smartphone.  
  
##Why would I need this?  
First of all, this makes access from any IP address than your own __impossible__.  
This means, that any person that doesn't have your phone / your email, can never log in into your account, __despite having your password__.  
Second of all, this is a excellent protection against database 'hacks' - 'hacks' meaning when the database gets breached/compromised.  
The specifics of these 2 statements get explained later down this document.

##How does smartphone authorization work?
Please read the main article on the wiki for this [here](https://github.com/jariz/2stepauth/wiki/HowDoesSmartphoneAuthWork)

##How does email authorization work?  
It's pretty similar to phone authorization, except with email.  
To futher expand on that: when the user tries to log in with a non-authorized IP, an email gets send, and the site will request for the authorization code in the email.  
Alternatively, the user can also click on the url in the email (but he will need to re-enter his credentials).  
If you want more information on this, [you can read this article](https://github.com/jariz/2stepauth/wiki/HowDoesSmartphoneAuthWork) (which covers mainly the phone authorization, but the process is very similar to email auth)
___________  
  
That's a brief explanation of the entire process, if you want more, check out the code!
