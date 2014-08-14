Given(/^I have a Flow message$/) do
  client = on(APIPage).client
  username = get_session_username_b()
  step 'the user "' + username + '" exists'
  client.log_in(username, ENV["MEDIAWIKI_PASSWORD"])
  client.action( 'flow', token_type: 'edit', submodule: 'new-topic', page: 'Talk:Flow QA',
    nttopic:'Mention #1', ntcontent: '[[User:' + get_session_username() + ']] I wanted to say hello.' )
end

When(/^I click the mark all as read button$/) do
  on(ArticlePage).mark_as_read_element.when_present.click
end
