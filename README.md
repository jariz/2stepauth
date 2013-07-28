2StepAuth
=========
  
#What is it?  
2StepAuth (2 step authorization) is a MyBB plugin created as a extra security layer on top of the normal login procedure.  
It uses the Google Authenticator app for the creation of authorization codes.  
  
#What is 2 step authorization and what type of 2 step auth does this plugin use?  
Method explained:
https://en.wikipedia.org/wiki/Multi-factor_authentication#Mobile_applications  
App explained:  
https://support.google.com/accounts/answer/1066447?hl=en  
  
#How does it work?  
By default, the user can set the system up at his user control panel. The administrator, however, can also force all users to use the system. (However, this is not recommended)  
The user can scan the QR code with Google Authenticator, this QR code holds the 'secret'.  
The GAuth app will use this 'secret' to create the codes that users will have to enter to authorize themselves.  
For more information on how it works, read the next chapter.  
  
#How secure is this?  
##The secrets
By default, all user secrets are encrypted with a 256 bits Rijndael encryption.  
The encryption key is stored in the config file, never in the database.  
If the database of your website gets compromised, they won't be able to get the secrets (unless they have file access, which is rarely the case with most hacks)  
##The verification and authorized IPs  
When a user logs in, the first thing the plugin will do is check if this IP is authorized to log in in the twostepauth_authorizations table.  
If this IP is not in the table, it'll prompt the user to enter the code on the GAuth app. This code changes every 30 seconds for additional security.  
Once entered, the secret will be decrypted and checked against the code.  
##Is this secure to 'Man in the middle' attacks?  
Short answer: The login procedure, Yes. Long answer:  
The GAuth app never 'directly' communicates with the site. It simply generates codes based on the time and secret.  
Codes, once entered, can also never be entered again, and, keep in mind that they also change every 30 seconds.  
The only vulnable point would be the usercp page with the QR code, if someone is listening in on the connection, they can intercept the QR code and get the secret (and thus, generate their own codes)  
  
___________  
  
That's a brief explanation of the entire process, if you want more, check out the code!
