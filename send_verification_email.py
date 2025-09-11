import smtplib
import sys
import random
import string
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart

def generate_verification_code(length=6):
    characters = string.digits
    return ''.join(random.choice(characters) for _ in range(length))

def send_email(recipient_email, smtp_server, smtp_port, sender_email, sender_password):
    """发送验证邮件并返回验证码和状态"""
    verification_code = generate_verification_code()
    
    # 邮件格式
    html_content = f"""
    <html>
    <head>
        <style>
            body {{ font-family: 'Arial', sans-serif; color: #333; }}
            .container {{ max-width: 600px; margin: 0 auto; padding: 20px; }}
            .header {{ background-color: #6366f1; color: white; padding: 20px; text-align: center; }}
            .content {{ padding: 20px; background-color: #f9f9f9; }}
            .code {{ font-size: 32px; font-weight: bold; text-align: center; color: #6366f1; 
                    margin: 20px 0; letter-spacing: 5px; }}
            .footer {{ text-align: center; margin-top: 20px; font-size: 12px; color: #888; }}
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>UKM NEXUS Email Verification</h1>
            </div>
            <div class="content">
                <p>Dear UKM Student,</p>
                <p>Thank you for registering with UKM NEXUS. To complete your registration, please use the verification code below:</p>
                <div class="code">{verification_code}</div>
                <p>This code will expire in 10 minutes.</p>
                <p>If you did not request this code, please ignore this email.</p>
            </div>
            <div class="footer">
                <p>This is an automated message, please do not reply to this email.</p>
                <p>&copy; 2025 UKM NEXUS. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    """
    
    # 创建电子邮件对象
    message = MIMEMultipart('alternative')
    message['From'] = sender_email
    message['To'] = recipient_email
    message['Subject'] = 'UKM NEXUS - Email Verification Code'
    
    # 添加 HTML 内容
    message.attach(MIMEText(html_content, 'html', 'utf-8'))
    
    try:
        smtp_obj = smtplib.SMTP_SSL(smtp_server, smtp_port)
        smtp_obj.login(sender_email, sender_password)
        smtp_obj.sendmail(sender_email, [recipient_email], message.as_string())
        smtp_obj.quit()
        print(f"{verification_code}|success")
        return True
    except Exception as e:
        print(f"error|{str(e)}")
        return False

if __name__ == "__main__":
    # 从命令行参数获取数据
    if len(sys.argv) != 6:
        print("error|Incorrect number of arguments")
        sys.exit(1)
    
    recipient_email = sys.argv[1]
    smtp_server = sys.argv[2]
    smtp_port = int(sys.argv[3])
    sender_email = sys.argv[4]
    sender_password = sys.argv[5]
    
    send_email(recipient_email, smtp_server, smtp_port, sender_email, sender_password)