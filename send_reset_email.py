import smtplib
import sys
import random
import string
from email.mime.text import MIMEText

def generate_reset_code(length=6):
    characters = string.digits
    return ''.join(random.choice(characters) for _ in range(length))

def send_simple_reset_email(recipient_email, username, smtp_server, smtp_port, sender_email, sender_password):
            # 生成随机密码  
    reset_code = generate_reset_code()

    content = f"""UKM NEXUS Password Reset

Hello {username},

Your password reset code: {reset_code}

Valid for 15 minutes.

UKM NEXUS """
    
    # 创建纯文本邮件
    message = MIMEText(content, 'plain', 'utf-8')
    message['From'] = sender_email
    message['To'] = recipient_email
    message['Subject'] = 'Password Reset Code'
    
    try:
        smtp_obj = smtplib.SMTP_SSL(smtp_server, smtp_port)
        smtp_obj.login(sender_email, sender_password)
        smtp_obj.sendmail(sender_email, [recipient_email], message.as_string())
        smtp_obj.quit()
        print(f"{reset_code}|success")
        return True
    except Exception as e:
        print(f"error|{str(e)}")
        return False

if __name__ == "__main__":
    if len(sys.argv) != 7:
        print("error|Incorrect number of arguments")
        sys.exit(1)
    
    recipient_email = sys.argv[1]
    username = sys.argv[2]  
    smtp_server = sys.argv[3]
    smtp_port = int(sys.argv[4])
    sender_email = sys.argv[5]
    sender_password = sys.argv[6]
    
    send_simple_reset_email(recipient_email, username, smtp_server, smtp_port, sender_email, sender_password)